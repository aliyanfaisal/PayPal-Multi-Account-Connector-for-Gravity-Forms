# PayPal Multi-Account Connector for Gravity Forms

This custom WordPress plugin allows you to connect a **Secondary PayPal Account** to Gravity Forms, selectively processing payments to a different PayPal account based on user input, while bypassing the primary PayPal Checkout (PPCP) feed.

## Features

- **Secondary PayPal Account Integration:** Seamlessly route specific payments to a secondary PayPal account.
- **Dynamic Trigger Logic:** Define a target Form ID, Field ID, and specific Field Value. When the user selects this value, the transaction is dynamically switched to the secondary PayPal account.
- **Environment Support:** Switch easily between **Live** and **Sandbox** environments via the settings page.
- **Automated Webhooks Setup:** One-click automated webhook generation and configuration for the secondary PayPal account to synchronize payment statuses securely.
- **Entry Meta Tracking:** Highlights in the Gravity Forms Entry details which PayPal account (Primary or Secondary) was used, and logs the respective transaction IDs correctly.

## Installation

1. Upload the plugin folder `gravity-forms-paypal-multi-account` to the `/wp-content/plugins/` directory, or install the ZIP file via the WordPress plugins page.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Ensure that Gravity Forms and the Gravity Forms PayPal Checkout Add-On are already installed and active.

## Configuration

1. In your WordPress admin dashboard, navigate to **PayPal Multi-Account** in the sidebar.
2. **Environment:** Choose between Sandbox and Live modes.
3. **Credentials:** Enter your **Client ID** and **Secret** for the chosen environment.
   - You can obtain these from your [PayPal Developer Dashboard](https://developer.paypal.com/dashboard/applications).
4. **Webhook:** Once credentials are saved, click **Automatically Setup Webhook** to let the plugin create the proper endpoints on your PayPal app. 
   - Alternatively, you can copy the provided webhook URL and set it up manually in your PayPal Developer Dashboard.
5. **Trigger Logic:**
   - **Target Form ID:** Enter the ID of the Gravity Form you want to target.
   - **Trigger Field ID:** Enter the ID of the specific field (like a radio button or dropdown) that determines the account choice.
   - **Trigger Value:** Enter the precise value that should activate the switch to the secondary PayPal account.

## How It Works

- The plugin conditionally hooks into Gravity Forms. If the user's selection matches the **Trigger Value**, the default PayPal integration is intercepted.
- A separate PayPal SDK script with a unique namespace (`paypalAccount2`) is loaded to handle the secondary account checkout.
- Upon successful payment:
  - The plugin captures the order using the secondary account credentials.
  - The entry is updated with the secondary payment details, marked as "Paid", and the default Gravity Forms PayPal Checkout feed is bypassed to prevent duplicate captures.
  - The custom entry highlight clearly identifies that the **Secondary Account** was used.

## Author

**Aliyan Faisal**
- Website: [aliyanfaisal.com](https://aliyanfaisal.com)
