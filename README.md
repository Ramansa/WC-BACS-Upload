# WooCommerce BACS Receipt Upload

A WordPress plugin that adds a **bank transfer receipt upload** section on the WooCommerce **My Account > View Order** page and in the **admin order page**.

## Features

- Shows upload tools only for orders paid via **Direct Bank Transfer (BACS)**.
- Customer can upload proof **after order placement** (not in cart/checkout).
- Customer order page shows transfer instructions and bank account info pulled from WooCommerce BACS settings.
- Admin can also upload/view/delete receipt from WooCommerce order edit page.
- Customer can view, delete, and re-upload until admin verifies payment.
- Admin can click **Verify Payment (Lock Upload)** to stop any further upload/delete for that order.
- Sends notification email (with file attachment) to multiple recipients you configure.

## Installation

1. Copy the folder `woocommerce-bacs-receipt-upload` into `wp-content/plugins/`.
2. Activate **WooCommerce BACS Receipt Upload** from WordPress plugins page.
3. Go to **WooCommerce > BACS Receipt Upload**.
4. Set recipient emails (comma-separated) and optional subject.

## Supported file types

- Images: JPG/JPEG, PNG, GIF, BMP, WEBP
- Documents: PDF, DOC/DOCX, XLS/XLSX, PPT/PPTX, TXT, RTF

