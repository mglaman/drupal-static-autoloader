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
        $extensions = $this->extensionDiscovery->scan($type);
        foreach ($extensions as $extension_name => $extension) {
            $path = $this->drupalRoot . '/' . $extension->getPath() . '/src';
            $this->autoloader->addPsr4("Drupal\\{$extension_name}\\", $path);

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
            if ($fileinfo->getFilename() === 'bootstrap.inc') {
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
