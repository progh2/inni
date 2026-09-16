<?php

use Inni\App;
use Inni\Asset;
use Inni\Auth;
use Inni\Support;

/** @var array $user */
$assetId = (string) ($assetId ?? '');
$kind = isset($kind) && is_string($kind) ? $kind : Asset::RETIRE_OTHER;
$reason = isset($reason) && is_string($reason) ? $reason : Asset::retireReasonForKind($kind);
$label = isset($label) && is_string($label) && $label !== '' ? $label : '불용·파기로 이어가기';
$hint = isset($hint) && is_string($hint) ? $hint : '';
if ($assetId === '' || !Auth::canWrite($user ?? null)) {
    return;
}
$query = ['id' => $assetId, 'focus' => 'retire', 'retire_kind' => $kind];
if ($reason !== '') {
    $query['reason'] = $reason;
}
?>
<p class="muted" style="margin:0.5rem 0 0">
  <?php if ($hint !== ''): ?><?= Support::e($hint) ?> · <?php endif; ?>
  <a class="btn btn-ink" href="<?= Support::e(App::url('assets/show', $query)) ?>#retire"><?= Support::e($label) ?></a>
</p>
