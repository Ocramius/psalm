<?php
namespace Psalm\Plugin;

use PhpParser;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use Psalm\Checker;
use Psalm\Checker\StatementsChecker;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\FileManipulation;
use Psalm\IssueBuffer;
use Psalm\Issue\TypeCoercion;
use Psalm\Plugin\Hook\AfterFunctionCallAnalysisInterface;
use Psalm\Plugin\Hook\AfterMethodCallAnalysisInterface;
use Psalm\StatementsSource;
use Psalm\Type\Union;

/**
 * Prevents any assignment to a float value
 */
class MethodMover implements AfterMethodCallAnalysisInterface
{

    /**
     * @param  MethodCall|StaticCall $expr
     * @param  FileManipulation[] $file_replacements
     *
     * @return void
     */
    public static function afterMethodCallAnalysis(
        Expr $expr,
        string $method_id,
        string $appearing_method_id,
        string $declaring_method_id,
        Context $context,
        StatementsSource $statements_source,
        Codebase $codebase,
        array &$file_replacements = [],
        Union &$return_type_candidate = null
    ) {
        if (!$expr->name instanceof PhpParser\Node\Identifier) {
            return;
        }

        $replacement_methods = $codebase->migrations;

        list($declaring_fq_class_name, $declaring_method_name) = explode('::', $declaring_method_id);

        if ($expr instanceof MethodCall) {
            $replacement_key = '\$(' . $declaring_fq_class_name . ')->' . $declaring_method_name . '(.*)';
        } else {
            $replacement_key = $declaring_fq_class_name . '::' . $declaring_method_name . '(.*)';
        }

        try {
            $function_storage = $codebase->methods->getStorage($declaring_method_id);

            $context->calling_method_id
        } catch (\Exception $e) {
            // can throw if storage is missing
        }
    }
}
