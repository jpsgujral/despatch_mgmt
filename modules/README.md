# Despatch Management System
## PHP CRUD Application with Delivery Challan Printing

---

## Features

### Modules Included
1. **Dashboard** – Overview stats, recent despatches, recent POs
2. **Vendor Master** – Full CRUD with contact, address, tax & bank details
3. **Item Master** – Products/materials with HSN, GST, stock tracking
4. **Transporter Master** – Carrier management with rates and bank info
5. **Purchase Orders** – PO creation with dynamic line items, totals, status
6. **Despatch Orders** – Full dispatch management with transport details
7. **Delivery Challans** – List, filter, and print delivery challans
8. **Transporter Payments** – Payment tracking against despatch orders
9. **Company Settings** – Configure your company info for challans

### Key Capabilities
- ✅ Full CRUD for all modules
- ✅ Printable Delivery Challan (3 copies: Original, Duplicate, Triplicate)
- ✅ Auto-generated document numbers (PO, Despatch, Challan, Payment)
- ✅ Dynamic item rows with auto-calculation (qty × price + GST)
- ✅ Amount in words on challan
- ✅ GST-compliant challan format with GSTIN fields
- ✅ DataTables for searchable, sortable grids
- ✅ Bootstrap 5 responsive UI with sidebar navigation

---

## Installation

### Requirements
- PHP 7.4+ (PHP 8.x recommended)
- MySQL 5.7+ or MariaDB 10+
- Apache or Nginx web server

### Steps

1. **Copy files** to your web root:
   ```
   /var/www/html/despatch_mgmt/   (Linux)
   C:\xampp\htdocs\despatch_mgmt\ (Windows/XAMPP)
   ```

2. **Create Database** – Open phpMyAdmin or MySQL CLI:
   ```sql
   SOURCE /path/to/despatch_mgmt/database.sql;
   ```
   Or paste contents of `database.sql` into phpMyAdmin SQL tab.

3. **Configure DB connection** – Edit `includes/config.php`:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_USER', 'your_mysql_user');
   define('DB_PASS', 'your_mysql_password');
   define('DB_NAME', 'despatch_mgmt');
   ```

4. **Access the app**:
   ```
   http://localhost/despatch_mgmt/
   ```

---

## File Structure

```
despatch_mgmt/
├── index.php                     ← Dashboard
├── database.sql                  ← DB schema + sample data
├── includes/
│   ├── config.php                ← DB config, helper functions
│   ├── header.php                ← Sidebar + HTML head
│   └── footer.php                ← Scripts + closing tags
└── modules/
    ├── vendors.php               ← Vendor Master CRUD
    ├── items.php                 ← Item Master CRUD
    ├── transporters.php          ← Transporter Master CRUD
    ├── purchase_orders.php       ← Purchase Order CRUD
    ├── despatch.php              ← Despatch Order CRUD
    ├── delivery_challans.php     ← Challan list + filter
    ├── print_challan.php         ← Print-ready challan (3 copies)
    ├── transporter_payments.php  ← Payment tracking CRUD
    └── company_settings.php      ← Company config
```

---

## Delivery Challan Format

The challan (`print_challan.php`) includes:
- Company letterhead with GSTIN
- Challan number, date, despatch number
- Consignee details with GSTIN
- Transporter & vehicle details
- LR number and date
- Itemized table (Item Code, HSN, UOM, Qty, Rate, GST, Total)
- Amount in words (Indian number system)
- Sub-total, GST, Freight, Grand Total
- Terms & Conditions
- Signature blocks (Prepared By, Checked By, Driver, Authorised)
- Prints 3 copies: Original / Duplicate / Triplicate

---

## Security Notes

For production use:
- Add user authentication (login system)
- Use prepared statements instead of string concatenation
- Add CSRF protection on forms
- Use HTTPS
- Restrict DB user permissions

---

## Sample Data

The `database.sql` includes:
- 2 sample vendors
- 5 sample items (Steel Pipes, Copper Wire, PVC Fittings, Bearings, Lubricant)
- 2 sample transporters
- Company defaults

---

*Built with PHP, MySQL, Bootstrap 5, DataTables*
