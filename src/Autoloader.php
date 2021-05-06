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

        $this->loadLegacyIncludes();

        $this->registerTestNamespaces();
    }

    private function registerExtensions(string $type)
    {
        if ($type !== 'module' && $type !== 'theme' && $type !== 'profile' && $type !== 'theme_engine') {
            throw new \InvalidArgumentException("Must be 'module' or 'theme' but got $type");
        }
        // Taken from drupal_phpunit_get_extension_namespaces.
        $suite_names = ['Unit', 'Kernel', 'Functional', 'Build', 'FunctionalJavascript'];
        $extensions = $this->extensionDiscovery->scan($type);
        foreach ($extensions as $extension_name => $extension) {
            $path = $this->drupalRoot . '/' . $extension->getPath() . '/src';
            $this->autoloader->addPsr4("Drupal\\{$extension_name}\\", $path);

            // @see drupal_phpunit_get_extension_namespaces().
            $test_dir = $this->drupalRoot . '/' . $extension->getPath() . '/tests/src';
            foreach ($suite_names as $suite_name) {
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
        $flags = \FilesystemIterator::UNIX_PATHS;
        $flags |= \FilesystemIterator::SKIP_DOTS;
        $flags |= \FilesystemIterator::FOLLOW_SYMLINKS;
        $flags |= \FilesystemIterator::CURRENT_AS_SELF;
        $directory_iterator = new \RecursiveDirectoryIterator($this->drupalRoot . '/core/includes', $flags);
        $iterator = new \RecursiveIteratorIterator(
            $directory_iterator,
            \RecursiveIteratorIterator::LEAVES_ONLY,
            // Suppress filesystem errors in case a directory cannot be accessed.
            \RecursiveIteratorIterator::CATCH_GET_CHILD
        );

        foreach ($iterator as $fileinfo) {
            // Check if this file was added as an autoloaded file in the Drupal
            // core composer.json file.
            // @note: In Drupal 8, includes/bootstrap.inc was not added, but it
            // was added in Drupal 9. This check handles any future includes
            // that are already registered.
            //
            // @see \Composer\Autoload\AutoloadGenerator::getFileIdentifier().
            $autoloadFileIdentifier = md5('drupal/core:includes/' . $fileinfo->getFilename());
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
