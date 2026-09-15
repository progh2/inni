<?php

use Inni\Auth;
use Inni\MaterialBoard;
use Inni\Support;

/** @var array $user */
/** @var string $itemId */
/** @var bool $offerRestock */
/** @var bool $offerIssue */
/** @var string $issueLabel */

$itemId = trim((string) ($itemId ?? ''));
$offerRestock = !empty($offerRestock) && Auth::canWrite($user);
$offerIssue = !empty($offerIssue) && Auth::canLoan($user);
$issueLabel = isset($issueLabel) && is_string($issueLabel) && $issueLabel !== '' ? $issueLabel : '분출';
if ($itemId === '' || (!$offerRestock && !$offerIssue)) {
    return;
}
?>
<?php if ($offerRestock): ?>
  <a class="btn btn-ink" href="<?= Support::e(MaterialBoard::itemFocusUrl($itemId, 'restock')) ?>">재입고</a>
<?php endif; ?>
<?php if ($offerIssue): ?>
  <a class="btn btn-primary" href="<?= Support::e(MaterialBoard::itemFocusUrl($itemId, 'issue')) ?>"><?= Support::e($issueLabel) ?></a>
<?php endif; ?>
