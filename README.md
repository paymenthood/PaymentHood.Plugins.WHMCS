# PaymentHood WHMCS Plugin

> A comprehensive payment gateway integration for WHMCS that enables seamless payment processing through PaymentHood's platform with support for one-time payments, recurring subscriptions, and automated payment handling.

[![WHMCS](https://img.shields.io/badge/WHMCS-Compatible-green.svg)](https://www.whmcs.com/)
[![PaymentHood](https://img.shields.io/badge/PaymentHood-Integration-blue.svg)](https://paymenthood.com/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://www.php.net/)

---

## 🌟 Features

### Core Payment Capabilities
- **Hosted Payment Pages**: Secure, PCI-compliant checkout experience
- **One-Time Payments**: Process single invoice payments
- **Recurring Subscriptions**: Automatic payment handling for subscription-based services
- **Auto-Capture**: Immediate payment capture upon authorization
- **Multi-Currency Support**: Process payments in various currencies

### Advanced Functionality
- **OAuth2 Integration**: Secure app activation and authorization
- **Webhook Support**: Real-time payment status notifications
- **Automated Payment Processing**: Cron-based auto-payment for due invoices
- **Payment Method Management**: Customer portal for managing saved payment methods
- **Invoice Synchronization**: Automatic status updates based on payment state
- **Duplicate Payment Prevention**: Intelligent handling of duplicate payment attempts

### Security & Reliability
- **Webhook Authentication**: Bearer token validation for incoming webhooks
- **Secure Credential Storage**: Encrypted storage of API credentials
- **Comprehensive Logging**: Detailed module call logging for debugging
- **Error Handling**: Graceful fallback mechanisms

---

## 📁 Project Structure

```
paymenthood-plugins/
│
├── README.md                                    # This file
│
└── whmcs/                                       # WHMCS integration root
    │
    ├── includes/
    │   └── hooks/
    │       └── paymenthood-cron-hook.php        # Cron job hook for automated payments
    │
    └── modules/
        ├── addons/
        │   └── paymenthood/
        │       ├── paymenthoodhandler.php       # Core handler class (business logic)
        │       └── templates/
        │           └── manage-subscription.tpl  # Customer-facing subscription UI
        │
        └── gateways/
            ├── paymenthood.php                  # Gateway configuration & entry point
            ├── whmcs.json                       # Module metadata for Apps & Integrations
            ├── paymenthood-logo.png             # Module logo
            └── callback/
                └── paymenthood.php              # Webhook & return URL handler
```

### Component Details

#### 🔧 **Gateway Module** (`modules/gateways/paymenthood/paymenthood.php`)
The main gateway configuration file that defines:
- Gateway metadata and display name
- OAuth2 activation flow
- App ID and access token management
- Webhook token registration
- Payment link generation for invoices

**Key Functions:**
- `paymenthood_config()` - Gateway configuration settings
- `paymenthood_link()` - Generates payment button/link for invoices
- `paymenthood_handleActivationReturn()` - Processes OAuth callback
- `paymenthood_syncWebhookToken()` - Registers webhook endpoints with PaymentHood

---

#### 🔄 **Callback Handler** (`modules/gateways/callback/paymenthood.php`)
Handles payment notifications and return URLs:
- **POST requests**: Webhook notifications from PaymentHood
- **GET requests**: Customer return after payment

**Payment State Processing:**
- `Captured` → Marks invoice as paid, applies payment
- `Failed` → Cancels invoice, logs failure
- `Processing` → Maintains pending state

**Security Features:**
- Webhook token validation via Bearer authentication
- HTTPS-only communication
- Request signature verification

---

#### 🎯 **Core Handler** (`modules/addons/paymenthood/paymenthoodhandler.php`)
Central business logic class containing:

**Payment Processing:**
- `handleInvoice()` - Main invoice payment flow
- `createAutoPayment()` - Automated recurring payments
- `checkInvoiceStatus()` - Payment state verification

**System Integration:**
- `getGatewayCredentials()` - Retrieves stored API credentials
- `getSystemUrl()` - Dynamic URL generation
- `callApi()` - Generic API communication wrapper

**Subscription Detection:**
- Automatically identifies recurring services
- Shows appropriate payment method options in checkout

---

#### ⏰ **Cron Hook** (`includes/hooks/paymenthood-cron-hook.php`)
Automated background task that:
- Runs after each WHMCS cron execution
- Identifies unpaid invoices with due dates matching subscription renewal dates
- Attempts automatic payment using saved payment methods
- Processes only invoices with recurring billing cycles
- Logs all auto-payment attempts

**Targeted Invoices:**
- Status: `Unpaid`
- Due date matches hosting next due date
- Billing cycle: Not `Free` or `One Time`
- Has recurring services attached

---

#### 🎨 **Customer Portal** (`modules/addons/paymenthood/templates/manage-subscription.tpl`)
Smarty template for subscription management:
- Displays verified payment methods
- Shows provider and masked card numbers
- Links to PaymentHood customer panel
- Add new payment methods interface

---

## 🚀 Installation

### Prerequisites
- WHMCS 7.0 or higher
- PHP 7.4 or higher
- cURL extension enabled
- MySQL/MariaDB database
- Valid PaymentHood merchant account

### Step 1: File Installation
1. Download the plugin files
2. Copy the `whmcs/` directory contents to your WHMCS installation root:
   ```
   /path/to/whmcs/includes/hooks/
   /path/to/whmcs/modules/addons/paymenthood/
   /path/to/whmcs/modules/gateways/paymenthood.php
   /path/to/whmcs/modules/gateways/whmcs.json
   /path/to/whmcs/modules/gateways/paymenthood-logo.png
   /path/to/whmcs/modules/gateways/callback/paymenthood.php
   ```

### Step 2: WHMCS Configuration
1. Log in to WHMCS Admin Panel
2. Navigate to **Setup → Payments → Payment Gateways**
3. Click on **All Payment Gateways** tab
4. Find **PaymentHood** and click **Activate**

### Step 3: PaymentHood Activation
1. After activating, click the **Activate PaymentHood** button
2. You'll be redirected to PaymentHood Console
3. Sign in with your merchant credentials
4. Grant authorization to the WHMCS app
5. You'll be redirected back to WHMCS with confirmation

### Step 4: Verify Installation
- Check **System Logs** for successful activation entries
- Ensure "Account is activated" message appears in gateway settings
- Test with a sample invoice

---

## ⚙️ Configuration

### Gateway Settings
The gateway automatically configures after OAuth activation:
- **App ID**: Your PaymentHood application identifier
- **Access Token**: OAuth-generated bearer token
- **Webhook Token**: Auto-generated for webhook authentication

### Webhook Configuration
Webhooks are automatically registered during activation:
- **Endpoint**: `https://yourdomain.com/modules/gateways/callback/paymenthood.php`
- **Authentication**: Bearer token (auto-generated)
- **Events**: Payment state changes

### Cron Configuration
Ensure WHMCS cron is running regularly:
```bash
*/5 * * * * php -q /path/to/whmcs/crons/cron.php
```

The PaymentHood cron hook runs automatically after each WHMCS cron execution.

---

## 💳 Payment Flow

### Customer Payment Journey

#### One-Time Payment
1. Customer views unpaid invoice
2. Clicks "Pay Now with PaymentHood" button
3. Redirected to PaymentHood hosted checkout
4. Enters payment details
5. Payment processed and captured
6. Redirected back to WHMCS with success message
7. Invoice automatically marked as paid

#### Recurring Subscription
1. System detects invoice with recurring service
2. Cron job identifies due invoice
3. Attempts auto-payment with saved payment method
4. If successful, invoice marked as paid
5. If failed, customer receives payment reminder
6. Customer can manually pay or update payment method

### Backend Processing Flow

```
Invoice Created
    ↓
[Is Recurring?] → YES → Save for Auto-Payment
    ↓ NO
Manual Payment Button Shown
    ↓
Customer Clicks Pay
    ↓
API Call to PaymentHood
    ↓
Hosted Page URL Returned
    ↓
Customer Redirected
    ↓
Payment Processed
    ↓
Webhook Received
    ↓
[Validate Token]
    ↓
[Check Payment State]
    ↓
Update Invoice Status
    ↓
Send Email Notification
```

---

## 🔌 API Integration

### PaymentHood Endpoints Used

#### App Management
- `POST /apps/{appId}/generate-bot-token` - OAuth token generation (Note: This endpoint is now handled via OAuth2 flow)
- `PATCH /apps/{appId}` - Webhook configuration

#### Payment Processing
- `POST /apps/{appId}/payments/hosted-page` - Create hosted payment
- `POST /apps/{appId}/payments/auto-payment` - Create automated payment
- `GET /apps/{appId}/payments/referenceId:{id}` - Retrieve payment status

### Base URLs
- **Production API**: `https://api.paymenthood.com/api/v1`
- **App API**: `https://appapi.paymenthood.com/api/`
- **Console**: `https://console.paymenthood.com`

---

## 🛠️ Development & Debugging

### Logging
All operations are logged via WHMCS's `logModuleCall()`:
- Navigate to **Utilities → Logs → Module Log**
- Filter by module: **paymenthood**

### Common Log Actions
- `link called` - Payment button generation
- `callback-status-check` - Webhook received
- `processUnpaidInvoices` - Cron execution
- `gateway activation return` - OAuth callback

### Testing Checklist
- [ ] Gateway activation successful
- [ ] Manual payment creates hosted page
- [ ] Webhook authentication works
- [ ] Payment state updates invoice
- [ ] Cron processes due invoices
- [ ] Duplicate payment handling
- [ ] Customer portal displays payment methods

---

## 🔐 Security Considerations

### Best Practices
1. **HTTPS Required**: All communication must use TLS/SSL
2. **Webhook Validation**: Always validates Bearer token
3. **SQL Injection Prevention**: Uses Capsule ORM with parameterized queries
4. **XSS Protection**: All output is HTML-escaped
5. **CSRF Protection**: WHMCS built-in token validation
6. **Credential Storage**: Tokens stored in database, never logged

### Token Security
- Access tokens never exposed to frontend
- Webhook tokens use cryptographically secure random generation
- Bearer authentication for all API calls

---

## 🐛 Troubleshooting

### Issue: Activation Button Doesn't Work
**Solution**: Check that your WHMCS system URL is correctly configured in **Setup → General Settings → General**.

### Issue: Webhooks Not Received
**Solutions**:
1. Verify webhook URL is accessible externally
2. Check firewall/security rules
3. Ensure webhook token was registered during activation
4. Review module logs for authentication failures

### Issue: Duplicate Payment Errors
**Solution**: The plugin automatically handles duplicates by checking existing payment state. If invoice was cancelled, it can be recreated.

### Issue: Auto-Payments Not Running
**Solutions**:
1. Verify WHMCS cron is executing
2. Check that services have non-free billing cycles
3. Ensure invoice due date matches hosting next due date
4. Review cron hook logs

### Issue: Payment Methods Not Showing
**Solution**: Ensure customer has completed at least one payment with saved payment method option selected.

---

## 📊 Database Tables Used

The plugin interacts with standard WHMCS tables:
- `tblpaymentgateways` - Gateway configuration storage
- `tblinvoices` - Invoice status updates
- `tblinvoiceitems` - Subscription detection
- `tblhosting` - Billing cycle information
- `tblmodulelog` - Debug logging
- `tblconfiguration` - System URL retrieval

---

## 🤝 Support & Contribution

### Getting Help
- **PaymentHood Support**: support@paymenthood.com
- **Documentation**: https://docs.paymenthood.com
- **WHMCS Forums**: Include "PaymentHood" in your post title

### Contributing
Contributions are welcome! Please:
1. Fork the repository
2. Create a feature branch
3. Follow PSR-12 coding standards
4. Include comprehensive comments
5. Test thoroughly before submitting PR

---

## 📄 License

This plugin is proprietary software provided by PaymentHood. All rights reserved.

---

## 🔄 Version History

### Version 1.0.0 (Current)
- Initial release
- OAuth2 activation flow
- Hosted payment pages
- Webhook integration
- Auto-payment for subscriptions
- Customer payment method management

---

## 📞 Contact

**PaymentHood**  
Website: https://paymenthood.com  
Email: support@paymenthood.com  
Documentation: https://docs.paymenthood.com

---

<div align="center">
  <strong>Built with ❤️ for seamless payment processing</strong>
</div>