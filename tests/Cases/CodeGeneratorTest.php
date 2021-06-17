<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace HyperfTest\Cases;

use Hyperf\CodeGenerator\Ast;
use Hyperf\CodeGenerator\CodeGenerator;
use HyperfTest\Stub\Foo;

/**
 * @internal
 * @coversNothing
 */
class CodeGeneratorTest extends AbstractTestCase
{
    public function testRewriteInjectClass()
    {
        $generator = new CodeGenerator(CodeGenerator::FLAG_INJECT, new Ast());
        $code = $generator->generate(Foo::class);
        $this->assertSame('<?php

declare (strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace HyperfTest\Stub;

use Hyperf\Di\Annotation\Inject;
class Foo
{
    #[Inject]
    public Bar $bar;
    #[Inject]
    public Bar $bar2;
    /**
     * No Inject.
     */
    public $bar3;
    #[Inject]
    public Bar $bar4;
}', $code);
    }
}
