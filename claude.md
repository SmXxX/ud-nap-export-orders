# UD НАП Orders Exporter — Claude working notes

Plugin by **Unbelievable Digital** (https://unbelievable.digital/).

## Purpose
WordPress/WooCommerce plugin that exports orders in the format required by the
Bulgarian National Revenue Agency (НАП / NRA) so that merchants can report sales
data as required by Bulgarian law.

## Current state
Scaffold only:
- `ud-nap-orders-exporter.php` — plugin header, bootstrap, WooCommerce check.
- `includes/class-ud-nap-exporter-plugin.php` — admin menu page with a date-range form and an `admin-post.php` handler stub (`handle_export`).
- No actual export logic yet.

## Prompt area
Act as a Senior WordPress & WooCommerce Developer. Your task is to create a custom WordPress plugin that generates a Standardized Audit XML File (SAF-T) as required by the Bulgarian National Revenue Agency (NAP) under Ordinance N-18 for e-commerce sites using the "alternative reporting method".

### Plugin Requirements:
1. **Admin Menu:** Add a sub-menu under "WooCommerce" titled "NAP XML Export".
2. **Date Range Filter:** UI to select a "Start Date" and "End Date" to filter orders.
3. **Column/Field Mapping:** Provide a settings page where I can map WooCommerce order fields (including custom meta fields like transaction IDs) to the required XML tags.
4. **XML Generation:** On click "Generate", the plugin must loop through all orders in the status 'processing' or 'completed' within the period and build a valid XML file based on the official XSD schema provided by NAP.

### XML Structure Details (Bulgarian Ordinance N-18):
- **Header:** Shop unique ID (registration number), Period, and Company Info.
- **Sales Rows:** For each order, include:
    - Order Number and Date.
    - Customer Data (Name/Email).
    - Product Details (SKU, Name, Quantity, Price, VAT Rate, Total).
    - Payment Info: Transaction ID (very important), Payment Method, and Payment Gateway Provider.
- **Support for Refunds:** Ability to include 'refund' type orders in the export.

### Technical Specifications:
- Use **XMLWriter** or **DOMDocument** in PHP for memory-efficient XML generation.
- Implement **AJAX or Action Scheduler** for the export process to prevent timeouts on shops with thousands of orders.
- Ensure the XML is downloadable as a `.xml` file.
- Use WordPress best practices (security nonces, capability checks for 'manage_woocommerce').

### Deliverable:
Provide the complete plugin folder structure and the main PHP file code. Make the code modular and well-commented.

PROMPT:

