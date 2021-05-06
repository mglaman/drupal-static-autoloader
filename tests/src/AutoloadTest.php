<?php declare(strict_types=1);

namespace mglaman\DrupalStaticAutoloader\Tests;

use mglaman\DrupalStaticAutoloader\Autoloader;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \mglaman\DrupalStaticAutoloader\Autoloader
 */
final class AutoloadTest extends TestCase {

  /**
   * @covers ::__construct
   *
   * @dataProvider pathsData
   */
  public function testConstructorInvalidArgument(string $path, string $expected_error) {
    if ($expected_error !== '') {
      $this->expectException(\InvalidArgumentException::class);
      $this->expectExceptionMessage($expected_error);
    } else {
      $this->expectNotToPerformAssertions();
    }
    new Autoloader($path);
  }

  public function pathsData(): \Generator {
    yield [
      '/foo/bar/baz',
      'Unable to read /foo/bar/baz',
    ];
    yield [
      sys_get_temp_dir(),
      'Unable to detect Drupal at ' . sys_get_temp_dir(),
    ];
    yield [
      __DIR__ . '/../fixtures/drupal',
      '',
    ];
    yield [
      __DIR__ . '/../fixtures/drupal/core/modules/action/src',
      '',
    ];
  }

  /**
   * Tests that a class name exists.
   *
   * @param string $class_name
   *   The class name to assert.
   *
   * @covers ::register
   * @dataProvider providesClasses
   * @dataProvider providesTestClasses
   * @dataProvider providesFunctions
   */
  public function testExists(string $exists_function, string $class_name): void {
    self::assertContains($exists_function, [
      'class_exists',
      'trait_exists',
      'interface_exists',
      'function_exists',
    ]);
    Autoloader::getLoader(__DIR__ . '/../fixtures/drupal')->register();
    self::assertTrue($exists_function($class_name), "$exists_function $class_name");
  }

  public function providesClasses() {
    // Baseline: dumped to autoloader.
    yield ['class_exists', \Drupal::class];
    yield ['class_exists', \Drupal\Core\PrivateKey::class];
    yield ['class_exists', \Drupal\Core\Template\Attribute::class];
    yield ['class_exists', \Drupal\action\ActionListBuilder::class];
    if (\Drupal::VERSION[0] >= 9) {
        yield ['class_exists', \Drupal\olivero\OliveroPreRender::class];
    }
    yield ['class_exists', \Drupal\autoload_fixture_profile\ClassInProfile::class];
  }

  public function providesFunctions() {
    // Drupal core includes
    yield ['function_exists', 'drupal_get_filename'];
    yield ['function_exists', 'theme_get_registry'];
    yield ['function_exists', 'drupal_get_schema_versions'];
    // Module extension file (.module)
    yield ['function_exists', 'action_entity_type_build'];
    // Profile extension file (.profile)
    yield ['function_exists', 'demo_umami_form_install_configure_form_alter'];
    // Theme extension file (.theme)
    yield ['function_exists', 'bartik_preprocess_html'];
    // Theme Engine extension file (.engine)
    yield ['function_exists', 'twig_theme'];
  }

  public function providesTestClasses() {
    yield ['class_exists', \Drupal\BuildTests\Composer\ComposerValidateTest::class];
    yield ['class_exists', \Drupal\FunctionalJavascriptTests\DrupalSelenium2Driver::class];
    yield ['class_exists', \Drupal\FunctionalTests\BrowserTestBaseUserAgentTest::class];
    yield ['class_exists', \Drupal\KernelTests\Component\Render\FormattableMarkupKernelTest::class];
    yield ['class_exists', \Drupal\Tests\ComposerIntegrationTest::class];
    yield ['class_exists', \Drupal\TestSite\Commands\TestSiteInstallCommand::class];
    yield ['class_exists', \Drupal\TestTools\PhpUnitCompatibility\RunnerVersion::class];
    // This trait is used by Functional tests.
    yield ['trait_exists', \Drupal\Tests\block\Traits\BlockCreationTrait::class];
  }

}
