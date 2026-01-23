# DirectPay Go

A high-performance WooCommerce custom checkout plugin built with React and optimized for 500+ concurrent visitors.

## Features

✅ **Zero-Bloat Architecture** - Client-side React frontend with minimal server processing  
✅ **High Performance** - Optimized for 500+ concurrent users on shared hosting  
✅ **Custom Amounts** - Accept manual payment amounts with custom references  
✅ **Multi-Step Checkout** - Clean UX with progress indicator  
✅ **Carrier Integration** - Supports Mondial Relay, Chronopost, and standard WooCommerce shipping  
✅ **Payment Gateway Ready** - Works with Stripe, PayPal, and other WooCommerce payment gateways  
✅ **Multilingual** - Built-in translation support (French/English)  
✅ **Mobile-First** - Responsive SCSS with mobile-first approach  
✅ **State Management** - Lightweight Zustand for React state  

## Tech Stack

- **Frontend**: React 18 + Vite + SCSS
- **State**: Zustand (lightweight alternative to Redux)
- **Backend**: PHP 8.0+ with WooCommerce
- **Build**: Vite (faster than Create React App)
- **API**: WordPress REST API + WooCommerce Store API

## Installation

### 1. Prerequisites

- WordPress 6.0+
- WooCommerce 8.0+
- PHP 8.0+
- Node.js 18+ and npm

### 2. Install Plugin

1. Upload the `directpay-go` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress

### 3. Build Frontend Assets

```bash
cd wp-content/plugins/directpay-go
npm install
npm run build
```

### 4. Create Checkout Page

1. Create a new page in WordPress
2. Add the shortcode: `[directpay_checkout]`
3. Publish the page

## Development

### Development Mode (Hot Reload)

```bash
npm run dev
```

This starts Vite dev server on `http://localhost:3000` with hot module replacement.

### Production Build

```bash
npm run build
```

Generates optimized assets in the `dist/` folder with:
- Minified JS/CSS
- Cache-busting hashes
- Tree-shaking
- Code splitting

### Watch Mode

```bash
npm run watch
```

Rebuilds on file changes (useful during development).

## File Structure

```
directpay-go/
├── dist/                      # Built assets (generated)
├── includes/
│   ├── class-api.php         # REST API endpoints
│   └── class-order.php       # Order creation logic
├── src/
│   ├── components/
│   │   ├── CheckoutForm.jsx  # Main form container
│   │   ├── FormStep.jsx      # Customer info step
│   │   ├── ShippingStep.jsx  # Shipping selection
│   │   ├── PaymentStep.jsx   # Payment selection
│   │   ├── ReviewStep.jsx    # Order review
│   │   └── LoadingSpinner.jsx
│   ├── store/
│   │   └── checkoutStore.js  # Zustand state management
│   ├── styles/
│   │   ├── main.scss         # Main styles
│   │   ├── variables.scss    # SCSS variables
│   │   └── mixins.scss       # SCSS mixins
│   ├── utils/
│   │   └── api.js            # API helper functions
│   ├── App.jsx               # Root component
│   └── main.jsx              # Entry point
├── templates/
│   └── checkout-page.php     # Custom page template
├── directpay-go.php          # Main plugin file
├── package.json              # Node dependencies
└── vite.config.js            # Vite configuration
```

## Usage

### Basic Usage

Simply add the shortcode to any page:

```
[directpay_checkout]
```

### Programmatic Order Creation

```php
$order_handler = new DirectPay_Go_Order();
$order = $order_handler->create_custom_order([
    'reference' => 'REF-12345',
    'amount' => 49.99,
    'customer' => [
        'email' => 'customer@example.com',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'address_1' => '123 Main St',
        'city' => 'Paris',
        'postcode' => '75001',
        'country' => 'FR',
        'phone' => '+33123456789',
    ],
    'payment_method' => 'stripe',
    'shipping_method' => 'flat_rate:1',
]);
```

## API Endpoints

### Create Order
```
POST /wp-json/directpay/v1/orders
```

**Body:**
```json
{
  "reference": "REF-123",
  "amount": 49.99,
  "customer": {
    "email": "customer@email.com",
    "first_name": "John",
    "last_name": "Doe"
  },
  "payment_method": "stripe",
  "shipping_method": "flat_rate:1"
}
```

### Get Shipping Methods
```
GET /wp-json/directpay/v1/shipping-methods
```

### Validate Reference
```
POST /wp-json/directpay/v1/validate-reference
Body: { "reference": "REF-123" }
```

## Performance Optimization

### For 500+ Concurrent Visitors:

1. **Enable Redis Object Cache** (Hostinger hPanel)
2. **Use Cloudflare** for static asset caching
3. **Enable Gzip Compression** in `.htaccess`
4. **Optimize Images** (WebP format)
5. **Lazy Load Components** if needed

### Recommended `.htaccess` for `/dist` folder:

```apache
<IfModule mod_expires.c>
  ExpiresActive On
  ExpiresByType text/css "access plus 1 year"
  ExpiresByType application/javascript "access plus 1 year"
  ExpiresByType image/png "access plus 1 year"
  ExpiresByType image/svg+xml "access plus 1 year"
</IfModule>

<IfModule mod_deflate.c>
  AddOutputFilterByType DEFLATE text/css application/javascript
</IfModule>
```

## Multilingual Support

The plugin uses `wp_localize_script` to pass translations from PHP to React:

```php
// In your theme's functions.php or a custom plugin
add_filter('directpay_go_translations', function($translations) {
    $translations['custom_label'] = __('My Custom Label', 'your-domain');
    return $translations;
});
```

## Hooks & Filters

### Actions

```php
// After order is created
do_action('directpay_go_order_created', $order, $data);
```

### Filters

```php
// Modify translations
apply_filters('directpay_go_translations', $translations);

// Modify order data before creation
apply_filters('directpay_go_order_data', $order_data);
```

## Carrier Integration

The plugin works with:
- **Mondial Relay** - Via WooCommerce Mondial Relay plugin
- **Chronopost** - Via WooCommerce Chronopost plugin
- **Standard WooCommerce Shipping** - Flat rate, free shipping, etc.

Shipping methods are automatically fetched from WooCommerce and displayed in the React frontend.

## Troubleshooting

### Assets Not Loading

1. Check if `dist/` folder exists: `npm run build`
2. Clear WordPress cache
3. Check console for errors

### API Errors

1. Check REST API: `/wp-json/directpay/v1/orders`
2. Verify WooCommerce is active
3. Check PHP error logs

### Shipping Methods Not Appearing

1. Go to WooCommerce → Settings → Shipping
2. Add shipping zones and methods
3. Ensure methods are enabled

## License

GPL v2 or later

## Support

For support, please open an issue on GitHub or contact your developer.

## Credits

Built with ❤️ for high-performance WooCommerce checkouts.
