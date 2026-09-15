<?php

use Inni\AssetLife;
use Inni\Support;

/** @var string $idSuffix */
/** @var string $lotCode */
/** @var string|null $expiresAt */
/** @var string|null $receivedAt */

$idSuffix = isset($idSuffix) && is_string($idSuffix) ? $idSuffix : '';
$lotCode = isset($lotCode) && is_string($lotCode) ? $lotCode : '';
$expiresAt = AssetLife::formatDate(isset($expiresAt) && is_string($expiresAt) ? $expiresAt : null);
$receivedAt = AssetLife::formatDate(isset($receivedAt) && is_string($receivedAt) ? $receivedAt : null);
$suffix = $idSuffix !== '' ? '-' . $idSuffix : '';
?>
<div class="grid-2">
  <div class="field">
    <label for="lot-code<?= Support::e($suffix) ?>">로트번호 (선택)</label>
    <input id="lot-code<?= Support::e($suffix) ?>" name="lot_code" maxlength="80" placeholder="예: LOT-2026-03" value="<?= Support::e($lotCode) ?>">
  </div>
  <div class="field">
    <label for="expires-at<?= Support::e($suffix) ?>">유통기한 (선택)</label>
    <input id="expires-at<?= Support::e($suffix) ?>" name="expires_at" type="date" value="<?= Support::e($expiresAt) ?>">
  </div>
</div>
<div class="field">
  <label for="received-at<?= Support::e($suffix) ?>">입고일 (선택)</label>
  <input id="received-at<?= Support::e($suffix) ?>" name="received_at" type="date" value="<?= Support::e($receivedAt) ?>">
</div>
