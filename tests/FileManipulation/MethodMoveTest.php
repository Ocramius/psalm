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
     * @param array<string, string> $call_transforms
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

        $codebase = $this->project_analyzer->getCodebase();

        $this->project_analyzer->refactorCodeAfterCompletion($methods_to_move);

        $this->analyzeFile($file_path, $context);

        $this->project_analyzer->prepareMigration();

        $codebase->analyzer->updateFile($file_path, false);

        $this->project_analyzer->migrateCode();

        $this->assertSame($output_code, $codebase->getFileContents($file_path));
    }

    /**
     * @return array<string,array{string,string,array<string, string>}>
     */
    public function providerValidCodeParse()
    {
        return [
            'moveSimpleStaticMethod' => [
                '<?php
                    namespace Ns;

                    use ArrayObject;

                    class A {
                        const C = 5;

                        /**
                         * @return ArrayObject<int, int>
                         */
                        public static function Foo() {
                            return new ArrayObject([self::C]);
                        }
                    }

                    class B {
                        public static function bar() : void {
                            A::Foo();

                            foreach (A::Foo() as $f) {}
                        }
                    }',
                '<?php
                    namespace Ns;

                    use ArrayObject;

                    class A {
                        const C = 5;


                    }

                    class B {
                        public static function bar() : void {
                            B::Fe();

                            foreach (B::Fe() as $f) {}
                        }
                        /**
                         * @return ArrayObject<int, int>
                         */
                        public static function Fe() {
                            return new ArrayObject([A::C]);
                        }

                    }',
                [
                    'Ns\A::Foo' => 'Ns\B::Fe',
                ]
            ],
            'moveStaticMethodIntoNamespaceWithExistingUse' => [
                '<?php
                    namespace {
                        class A {
                            public static function Foo() : void {}
                        }
                    }

                    namespace Ns {
                        use A;

                        class C {
                            public static function Bar() : void {
                                A::Foo();
                            }
                        }
                    }

                    namespace Ns\A {
                        class B {

                        }
                    }',
                '<?php
                    namespace {
                        class A {

                        }
                    }

                    namespace Ns {
                        use A;

                        class C {
                            public static function Bar() : void {
                                \Ns\A\B::Fedcba();
                            }
                        }
                    }

                    namespace Ns\A {
                        class B {


                            public static function Fedcba() : void {}
                        }
                    }',
                [
                    'A::Foo' => 'Ns\A\B::Fedcba',
                ]
            ],
            'moveEmptyStaticMethodOnly' => [
                '<?php
                    namespace Ns;

                    class A {
                        /**
                         * @return void
                         */
                        public static function Foo() : void {}
                    }

                    class B {
                    }',
                '<?php
                    namespace Ns;

                    class A {

                    }

                    class B {

                        /**
                         * @return void
                         */
                        public static function Fedcba() : void {}
                    }',
                [
                    'Ns\A::Foo' => 'Ns\B::Fedcba',
                ],
            ],
            'moveStaticMethodOnly' => [
                '<?php
                    namespace Ns;

                    class A {
                        const C = 5;

                        /**
                         * @param self $a1
                         * Some description
                         * @param ?self
                         *        $a2
                         * @param array<
                         *     int,
                         *     self
                         * > $a3
                         * @return self
                         */
                        public static function Foo(self $a1, ?self $a2, array $a3) : self {
                            echo self::C;
                            echo A::C;
                            self::Bar();
                            A::Bar();
                            echo \Ns\B::D;
                            new A();
                            /** @var self */
                            $a = new self();
                            new B();

                            return $a;
                        }

                        public static function Bar() : void {}
                    }

                    class B {
                        const D = 5;
                    }',
                '<?php
                    namespace Ns;

                    class A {
                        const C = 5;



                        public static function Bar() : void {}
                    }

                    class B {
                        const D = 5;

                        /**
                         * @param A $a1
                         * Some description
                         * @param null|A
                         *        $a2
                         * @param array<int, A> $a3
                         * @return A
                         */
                        public static function Fedbca(A $a1, ?A $a2, array $a3) : A {
                            echo A::C;
                            echo A::C;
                            A::Bar();
                            A::Bar();
                            echo self::D;
                            new A();
                            /** @var A */
                            $a = new A();
                            new self();

                            return $a;
                        }
                    }',
                [
                    'Ns\A::Foo' => 'Ns\B::Fedbca',
                ]
            ],
            'moveStaticMethodAndReferencesFromAbove' => [
                '<?php
                    namespace Ns;

                    class A {
                        const C = 5;

                        /**
                         * @return void
                         */
                        public static function Foo() : void {
                            echo self::C;
                        }
                    }

                    class B {
                        public static function bar() : void {
                            A::Foo();
                        }
                    }',
                '<?php
                    namespace Ns;

                    class A {
                        const C = 5;


                    }

                    class B {
                        public static function bar() : void {
                            B::Fe();
                        }

                        /**
                         * @return void
                         */
                        public static function Fe() : void {
                            echo A::C;
                        }
                    }',
                [
                    'Ns\A::Foo' => 'Ns\B::Fe',
                ]
            ],
            'moveStaticMethodAndReferencesFromBelow' => [
                '<?php
                    namespace Ns;

                    class B {
                        public static function bar() : void {
                            A::Foo();
                        }
                    }

                    class A {
                        const C = 5;

                        /**
                         * @return void
                         */
                        public static function Foo() : void {
                            echo self::C;
                        }
                    }',
                '<?php
                    namespace Ns;

                    class B {
                        public static function bar() : void {
                            B::Fe();
                        }

                        /**
                         * @return void
                         */
                        public static function Fe() : void {
                            echo A::C;
                        }
                    }

                    class A {
                        const C = 5;

                    }',
                [
                    'Ns\A::Foo' => 'Ns\B::Fe',
                ],
            ],
            'moveStaticMethodAndReferencesAcrossNamespaces' => [
                '<?php
                    namespace Ns1 {
                        class A {
                            const C = 5;

                            /**
                             * @return void
                             */
                            public static function Foo() : void {
                                echo self::C;
                            }
                        }
                    }

                    namespace Ns2\Ns3 {
                        class B {
                            public static function bar() : void {
                                \Ns1\A::Foo();
                            }
                        }
                    }',
                '<?php
                    namespace Ns1 {
                        class A {
                            const C = 5;


                        }
                    }

                    namespace Ns2\Ns3 {
                        class B {
                            public static function bar() : void {
                                B::Fe();
                            }

                            /**
                             * @return void
                             */
                            public static function Fe() : void {
                                echo \Ns1\A::C;
                            }
                        }
                    }',
                [
                    'Ns1\A::Foo' => 'Ns2\Ns3\B::Fe',
                ]
            ],
            'moveStaticMethodAndReferencesAcrossNamespacesWithExistingUse' => [
                '<?php
                    namespace Ns1 {
                        class A {
                            const C = 5;

                            /**
                             * @return void
                             */
                            public static function Foo() : void {
                                echo self::C;
                            }
                        }
                    }

                    namespace Ns2\Ns3 {
                        use Ns1\A;

                        class B {
                            public static function bar() : void {
                                \Ns1\A::Foo();
                            }
                        }
                    }',
                '<?php
                    namespace Ns1 {
                        class A {
                            const C = 5;


                        }
                    }

                    namespace Ns2\Ns3 {
                        use Ns1\A;

                        class B {
                            public static function bar() : void {
                                B::Fedcba();
                            }

                            /**
                             * @return void
                             */
                            public static function Fedcba() : void {
                                echo A::C;
                            }
                        }
                    }',
                [
                    'Ns1\A::Foo' => 'Ns2\Ns3\B::Fedcba',
                ]
            ],
        ];
    }
}
