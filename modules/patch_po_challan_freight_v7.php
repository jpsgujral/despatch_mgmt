<?php
$file = __DIR__ . '/../modules/purchase_orders.php';
$src  = file_get_contents($file);

$old = "                                    <td class=\"text-end fw-semibold\">\r\n                                        <?php if ((float)\$ch['total_weight'] > 0): ?>\r\n                                            \xE2\x82\xB9<?= number_format((float)\$ch['freight_amount'], 2) ?>\r\n                                        <?php else: ?>\r\n                                            <span class=\"text-muted\">\xE2\x82\xB9<?= number_format((float)\$ch['transporter_rate_per_mt'], 2) ?>/MT</span>\r\n                                        <?php endif; ?>\r\n                                    </td>";

$new = "                                    <td class=\"text-end fw-semibold\">\xE2\x82\xB9<?= number_format((float)\$ch['transporter_rate_per_mt'], 2) ?>/MT</td>";

if (strpos($src, $old) === false) {
    die("❌ String NOT found — no changes made.\n");
}

file_put_contents($file, str_replace($old, $new, $src));
echo "✅ purchase_orders.php patched — Freight column now always shows rate/MT.\n";
