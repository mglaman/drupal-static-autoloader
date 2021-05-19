<?php declare(strict_types=1);

namespace mglaman\DrupalStaticAutoloader;

use DrupalFinder\DrupalFinder;
use mglaman\DrupalStaticAutoloader\Drupal\ExtensionDiscovery;
use mglaman\DrupalStaticAutoloader\Drupal\Extension;

final class Autoloader
{

  /**
   * @var string
   */
    private $drupalRoot;

  /**
   * @var string
   */
    private $drupalVendorRoot;

  /**
   * @var \Composer\Autoload\ClassLoader
   */
    private $autoloader;

  /**
   * @var \mglaman\DrupalStaticAutoloader\Drupal\ExtensionDiscovery
   */
    private $extensionDiscovery;

    // Taken from drupal_phpunit_get_extension_namespaces.
    private const PHPUNIT_TEST_SUITES = ['Unit', 'Kernel', 'Functional', 'Build', 'FunctionalJavascript'];

    public static function getLoader(string $root): self
    {
        return new self($root);
    }

    public function __construct(string $root)
    {
        if (!is_readable($root)) {
            throw new \InvalidArgumentException("Unable to read $root");
        }
        $finder = new DrupalFinder();
        $finder->locateRoot($root);
        $drupalRoot = $finder->getDrupalRoot();
        $drupalVendorRoot = $finder->getVendorDir();
        if (! (bool) $drupalRoot || ! (bool) $drupalVendorRoot) {
            throw new \InvalidArgumentException("Unable to detect Drupal at $root");
        }
        $this->drupalRoot = $drupalRoot;
        $this->drupalVendorRoot = $drupalVendorRoot;
        $this->autoloader = include $drupalVendorRoot . '/autoload.php';
    }

    public function register(): void
    {
        $this->loadLegacyIncludes();

        $this->extensionDiscovery = new ExtensionDiscovery($this->drupalRoot);

      // Discover all available profiles.
        $profiles = $this->extensionDiscovery->scan('profile');
        $profile_directories = array_map(static function (Extension $profile) : string {
            return $profile->getPath();
        }, $profiles);
        $this->extensionDiscovery->setProfileDirectories($profile_directories);

        $this->registerExtensions('module');
        $this->registerExtensions('theme');
        $this->registerExtensions('profile');
        $this->registerExtensions('theme_engine');

        $this->registerTestNamespaces();
        $this->loadDrushIncludes();
    }

    private function registerExtensions(string $type)
    {
        if ($type !== 'module' && $type !== 'theme' && $type !== 'profile' && $type !== 'theme_engine') {
            throw new \InvalidArgumentException("Must be 'module' or 'theme' but got $type");
        }
        // Tracks implementations of hook_hook_info for loading of those files.
        $hook_info_implementations = [];

        $extensions = $this->extensionDiscovery->scan($type);
        foreach ($extensions as $extension_name => $extension) {
            $path = $this->drupalRoot . '/' . $extension->getPath() . '/src';
            $this->autoloader->addPsr4("Drupal\\{$extension_name}\\", $path);

            // @see drupal_phpunit_get_extension_namespaces().
            $test_dir = $this->drupalRoot . '/' . $extension->getPath() . '/tests/src';
            foreach (self::PHPUNIT_TEST_SUITES as $suite_name) {
                $suite_dir = $test_dir . '/' . $suite_name;
                if (is_dir($suite_dir)) {
                    $this->autoloader->addPsr4("Drupal\\Tests\{$extension_name}\\$suite_name\\", $path);
                }
            }
            // Extensions can have a \Drupal\extension\Traits namespace for
            // cross-suite trait code.
            $trait_dir = $test_dir . '/Traits';
            if (is_dir($trait_dir)) {
                $this->autoloader->addPsr4('Drupal\\Tests\\' . $extension_name . '\\Traits\\', $trait_dir);
            }

            $this->loadExtension($extension);

            // Mimics the buildHookInfo method in the module handler.
            // @see \Drupal\Core\Extension\ModuleHandler::buildHookInfo
            if ($type === 'module' || $type === 'profile') {
                $hook_info_function = $extension_name . '_hook_info';
                if (function_exists($hook_info_function)) {
                    $result = $hook_info_function();
                    if (is_array($result)) {
                        $groups = array_unique(array_values(array_map(static function (array $hook_info) {
                            return $hook_info['group'];
                        }, $result)));
                        // We do not need the full array structure, we only care
                        // about the group name for loading files.
                        $hook_info_implementations[] = $groups;
                    }
                }
            }
        }

        // Iterate over hook_hook_info implementations and load those files.
        if (count($hook_info_implementations) > 0) {
            $hook_info_implementations = array_merge(...$hook_info_implementations);
            foreach ($hook_info_implementations as $hook_info_group) {
                foreach ($extensions as $extension_name => $extension) {
                    $include_file = $this->drupalRoot . '/' . $extension->getPath() . '/' . $extension_name . '.' . $hook_info_group . '.inc';
                    if (file_exists($include_file)) {
                        include_once $include_file;
                    }
                }
            }
        }
    }

    private function loadExtension(Extension $extension): void
    {
        try {
            $extension->load();
        } catch (\Throwable $e) {
          // Something prevented the extension file from loading.
          // This can happen when drupal_get_path or drupal_get_filename are used outside of the scope of a function.
        }
    }

    private function loadLegacyIncludes(): void
    {
        $this->loadIncludesByDirectory('drupal/core', $this->drupalRoot . '/core/includes');
    }

    private function loadDrushIncludes()
    {
        if (class_exists(\Drush\Drush::class)) {
            $reflect = new \ReflectionClass(\Drush\Drush::class);
            if ($reflect->getFileName() !== false) {
                $levels = 2;
                if (\Drush\Drush::getMajorVersion() < 9) {
                    $levels = 3;
                }
                $drushDir = dirname($reflect->getFileName(), $levels);
                $this->loadIncludesByDirectory('drush/drush', $drushDir);
            }
        }
    }

    private function loadIncludesByDirectory(string $package, string $absolute_path): void
    {
        $flags = \FilesystemIterator::UNIX_PATHS;
        $flags |= \FilesystemIterator::SKIP_DOTS;
        $flags |= \FilesystemIterator::FOLLOW_SYMLINKS;
        $flags |= \FilesystemIterator::CURRENT_AS_SELF;
        $directory_iterator = new \RecursiveDirectoryIterator($absolute_path, $flags);
        $iterator = new \RecursiveIteratorIterator(
            $directory_iterator,
            \RecursiveIteratorIterator::LEAVES_ONLY,
            // Suppress filesystem errors in case a directory cannot be accessed.
            \RecursiveIteratorIterator::CATCH_GET_CHILD
        );

        foreach ($iterator as $fileinfo) {
            // Check if this file was added as an autoloaded file in a
            // composer.json file.
            //
            // @see \Composer\Autoload\AutoloadGenerator::getFileIdentifier().
            $autoloadFileIdentifier = md5($package . ':includes/' . $fileinfo->getFilename());
            if (isset($GLOBALS['__composer_autoload_files'][$autoloadFileIdentifier])) {
                continue;
            }
            if ($fileinfo->getExtension() === 'inc') {
                require_once $fileinfo->getPathname();
            }
        }
    }

  /**
   * @see drupal_phpunit_populate_class_loader
   */
    private function registerTestNamespaces()
    {
      // Start with classes in known locations.
        $dir = $this->drupalRoot . '/core/tests';
        $this->autoloader->add('Drupal\\BuildTests', $dir);
        $this->autoloader->add('Drupal\\Tests', $dir);
        $this->autoloader->add('Drupal\\TestSite', $dir);
        $this->autoloader->add('Drupal\\KernelTests', $dir);
        $this->autoloader->add('Drupal\\FunctionalTests', $dir);
        $this->autoloader->add('Drupal\\FunctionalJavascriptTests', $dir);
        $this->autoloader->add('Drupal\\TestTools', $dir);
    }
}
