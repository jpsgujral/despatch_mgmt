#!/usr/bin/env python3
import sys

file = '/home/tsgimpex/public_html/despatch_mgmt/modules/purchase_orders.php'

with open(file, 'rb') as f:
    data = f.read()

old = (
    b'class="text-end fw-semibold">\r\n'
    b'                                        <?php if ((float)$ch[\'total_weight\'] > 0): ?>\r\n'
    b'                                            \xe2\x82\xb9<?= number_format((float)$ch[\'freight_amount\'], 2) ?>\r\n'
    b'                                        <?php else: ?>\r\n'
    b'                                            <span class="text-muted">\xe2\x82\xb9<?= number_format((float)$ch[\'transporter_rate_per_mt\'], 2) ?>/MT</span>\r\n'
    b'                                        <?php endif; ?>\r\n'
    b'                                    </td>'
)

new = b'class="text-end fw-semibold">\xe2\x82\xb9<?= number_format((float)$ch[\'transporter_rate_per_mt\'], 2) ?>/MT</td>'

if old not in data:
    print("\u274c String NOT found \u2014 no changes made.")
    sys.exit(1)

data = data.replace(old, new, 1)

with open(file, 'wb') as f:
    f.write(data)

print("\u2705 purchase_orders.php patched \u2014 Freight column now always shows rate/MT.")
