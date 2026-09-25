<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** Compare actual baseline/current package validators on a balanced, otherwise-valid duplicate fixture. */
$root = dirname(__DIR__);
$baseline = in_array('--baseline', $argv, true);
require_once $baseline
    ? $root . '/.workflow/toeic-bank-fix-20260923-1518/baseline/includes/toeic_c2_package_importer.php'
    : $root . '/includes/toeic_c2_package_importer.php';
/** Explicit synthetic question with valid, balanced options and a sufficient explanation. */
function gateQuestion(string $stem): array {
    return ['question_text'=>$stem,'options'=>['A'=>'approve','B'=>'reject','C'=>'wait','D'=>'review'],'correct_answer'=>'A',
        'explanation'=>'Synthetic fixture explaining the expected answer without testing educational quality.'];
}
$a = gateQuestion('Choose the correct verb.');
$b = gateQuestion('Choose the second verb.');
$valid = ['manifest'=>['target_cefr'=>'C2'],'part5'=>['items'=>[$a,$b]]];
if (toeicC2ValidatePackage($valid, 99) !== []) { throw new RuntimeException('SETUP FAIL balanced distinct fixture must pass the existing quality requirements'); }
echo "PASS distinct balanced fixture is valid before negative probe\n";
$b = $a; $b['options']['A']='reject'; $b['options']['B']='approve'; $b['correct_answer']='B';
$duplicate = ['manifest'=>['target_cefr'=>'C2'],'part5'=>['items'=>[$a,$b]]];
$errors = toeicC2ValidatePackage($duplicate, 99);
if (count($errors) !== 1 || !str_contains(strtolower($errors[0]), 'duplicate content')) {
    throw new RuntimeException('FAIL otherwise-valid rotated duplicate must be rejected by the actual import validator');
}
echo "PASS actual package validator blocks rotated duplicate content\n";
$p = ['part7'=>['single_sets'=>[['set_id'=>'one','passage_1'=>'Finance policy.','questions'=>[$a]],['set_id'=>'two','passage_1'=>'Legal policy.','questions'=>[$a]]]]];
if (toeicC2PackageDuplicateErrors($p) !== []) { throw new RuntimeException('FAIL generic stem across genuinely different passages must stay valid'); }
echo "PASS different passages with same generic question remain distinct\n";
$p['part7']['single_sets'][1]['passage_1']='Finance policy.';
if (count(toeicC2PackageDuplicateErrors($p)) !== 1) { throw new RuntimeException('FAIL cloned article with different set IDs must be detected'); }
echo "PASS repeated article and question detected across different set IDs\n";
$p['part7']['single_sets'][1]['passage_2']='A distinct second document';
if (toeicC2PackageDuplicateErrors($p) !== []) { throw new RuntimeException('FAIL second document participates in package identity'); }
echo "PASS multiple-document identity preserved\n";
