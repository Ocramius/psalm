<?php
namespace Psalm\Tests\FileManipulation;

use Psalm\Context;
use Psalm\Internal\Analyzer\FileAnalyzer;
use Psalm\Tests\Internal\Provider;
use Psalm\Tests\TestConfig;

class MoveMethodTest extends \Psalm\Tests\TestCase
{
    /** @var \Psalm\Internal\Analyzer\ProjectAnalyzer */
    protected $project_analyzer;

    public function setUp() : void
    {
        FileAnalyzer::clearCache();
        \Psalm\Internal\FileManipulation\FunctionDocblockManipulator::clearCache();

        $this->file_provider = new Provider\FakeFileProvider();
    }

    /**
     * @dataProvider providerValidCodeParse
     *
     * @param string $input_code
     * @param string $output_code
     * @param array<string, string> $methods_to_move
     *
     * @return void
     */
    public function testValidCode(
        string $input_code,
        string $output_code,
        array $methods_to_move
    ) {
        $test_name = $this->getTestName();
        if (strpos($test_name, 'SKIPPED-') !== false) {
            $this->markTestSkipped('Skipped due to a bug.');
        }

        $config = new TestConfig();

        $this->project_analyzer = new \Psalm\Internal\Analyzer\ProjectAnalyzer(
            $config,
            new \Psalm\Internal\Provider\Providers(
                $this->file_provider,
                new Provider\FakeParserCacheProvider()
            )
        );

        $context = new Context();

        $file_path = self::$src_dir_path . 'somefile.php';

        $this->addFile(
            $file_path,
            $input_code
        );

        $this->project_analyzer->alterCodeAfterCompletion(
            false,
            false
        );

        $this->analyzeFile($file_path, $context);

        $this->project_analyzer->checkClassReferences();

        $codebase = $this->project_analyzer->getCodebase();

        $codebase->migrations = $methods_to_move;

        $codebase->analyzer->updateFile($file_path, false);
        $this->assertSame($output_code, $codebase->getFileContents($file_path));
    }

    /**
     * @return array<string,array{string,string,array<string, string>}>
     */
    public function providerValidCodeParse() {
        return [
            'moveStaticMethodWithNoReferences' => [
                '<?php
                    class A {
                        const FOO = 5;

                        public static function foo() : void {
                            echo self::FOO;
                        }
                    }

                    class B {
                        public static function bar() : void {}
                    }',
                '<?php
                    class A {
                        const FOO = 5;
                    }

                    class B {
                        public static function bar() : void {}

                        public static function foo() : void {
                            echo A::FOO;
                        }
                    }',
                [
                    'A::foo' => 'B::foo',
                    'A::foo\((.*\))' => 'B::foo($1)',
                ]
            ]
        ];
    }
}
