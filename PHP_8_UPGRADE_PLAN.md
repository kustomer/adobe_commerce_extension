# PHP 8.3/8.4 Compatibility Upgrade Plan

## Context

This Adobe Commerce extension (Kustomer Webhook Integration) currently supports only PHP 8.1 and 8.2. The user wants to upgrade it to support PHP 8.3 and 8.4.

**The Problem:**
PHP 8.2 deprecated dynamic property creation, and PHP 8.3+ throws fatal errors when undeclared properties are assigned. This codebase has 6 files with undeclared properties that will cause immediate runtime failures in PHP 8.3+.

**Impact if not fixed:**
- Extension will crash on PHP 8.3+ with "Cannot access undeclared property" errors
- All webhook functionality will break
- Admin panel features (event log, export, retry) will fail

**The Solution:**
Fix all undeclared property issues and update composer.json to declare PHP 8.3/8.4 support.

---

## Implementation Steps

### Step 1: Fix Helper/Data.php - Add Missing Property Declarations

**File:** `Helper/Data.php`

**Issue:** Lines 61-62 assign `$this->logger` and `$this->eventFactory` without property declarations.

**Fix:** Add property declarations after line 47 (after `$_orderRepository` declaration):

```php
/**
 * @var \Psr\Log\LoggerInterface
 */
protected $logger;

/**
 * @var EventFactory
 */
protected $eventFactory;
```

---

### Step 2: Fix Observer/Order.php - Add Missing Property Declaration

**File:** `Observer/Order.php`

**Issue:** Line 17 assigns `$this->helper` without property declaration.

**Fix:** Add property declaration after line 9 (after class declaration, before constructor):

```php
/**
 * @var Data
 */
protected $helper;
```

---

### Step 3: Fix Observer/Customer.php - Add Missing Property Declaration

**File:** `Observer/Customer.php`

**Issue:** Constructor assigns `$this->helper` without property declaration.

**Fix:** Add property declaration after line 9 (after class declaration):

```php
/**
 * @var Data
 */
protected $helper;
```

---

### Step 4: Fix Observer/Address.php - Add Missing Property Declaration

**File:** `Observer/Address.php`

**Issue:** Constructor assigns `$this->helper` without property declaration.

**Fix:** Add property declaration after line 9 (after class declaration):

```php
/**
 * @var Data
 */
protected $helper;
```

---

### Step 5: Fix Observer/OrderAddress.php - Add Missing Property Declarations

**File:** `Observer/OrderAddress.php`

**Issue:** Lines 19-20 assign `$this->helper` and `$this->_orderRepository` without property declarations.

**Fix:** Add property declarations after line 10 (after class declaration, before constructor):

```php
/**
 * @var Data
 */
protected $helper;

/**
 * @var OrderRepositoryInterface
 */
protected $_orderRepository;
```

---

### Step 6: Fix Controller/Adminhtml/Event/Export.php - Add Missing Property Declarations

**File:** `Controller/Adminhtml/Event/Export.php`

**Issue:** Lines 24 and 26 assign `$this->_resultFactory` and `$this->_urlBuilder` without property declarations. Only `$_webhookHelper` is declared (line 16).

**Fix:** Add property declarations after line 12 (before the existing `$_webhookHelper` declaration):

```php
/**
 * @var ResultFactory
 */
protected $_resultFactory;

/**
 * @var UrlInterface
 */
protected $_urlBuilder;
```

---

### Step 7: Update composer.json - Add PHP 8.3/8.4 Support

**File:** `composer.json`

**Current:** Line 8 has `"php": "~8.1.0|~8.2.0"`

**Fix:** Update to support PHP 8.3 and 8.4:

```json
"php": "~8.1.0|~8.2.0|~8.3.0|~8.4.0"
```

**Alternative (recommended):** Use caret constraint for automatic future support:

```json
"php": "^8.1"
```

This automatically includes 8.1, 8.2, 8.3, 8.4, and future 8.x versions.

---

## Critical Files to Modify

1. `Helper/Data.php` - Add 2 property declarations
2. `Observer/Order.php` - Add 1 property declaration
3. `Observer/Customer.php` - Add 1 property declaration
4. `Observer/Address.php` - Add 1 property declaration
5. `Observer/OrderAddress.php` - Add 2 property declarations
6. `Controller/Adminhtml/Event/Export.php` - Add 2 property declarations
7. `composer.json` - Update PHP version constraint

**Total:** 7 files, 10 property declarations + 1 composer.json update

---

## Verification Steps

After making all changes, verify compatibility:

### 1. Check Syntax (PHP 8.3/8.4)

If you have PHP 8.3 or 8.4 installed locally:

```bash
# Check each PHP file for syntax errors
php -l Helper/Data.php
php -l Observer/*.php
php -l Controller/Adminhtml/Event/*.php
```

### 2. Validate Composer

```bash
composer validate
composer install --dry-run
```

### 3. Deploy to Test Environment

Deploy to a Magento instance running PHP 8.3 or 8.4 and test:

- **Observer Events:** Trigger each observer to ensure no property errors:
  - Create/edit a customer → triggers `Observer/Customer.php`
  - Create/edit a customer address → triggers `Observer/Address.php`
  - Create/edit an order → triggers `Observer/Order.php`
  - Update order address in admin → triggers `Observer/OrderAddress.php`

- **Admin Panel Features:**
  - Access Kustomer → Event Logs
  - Test "Export to JSON" button → uses `Controller/Adminhtml/Event/Export.php`
  - Test "Retry" button on an event → uses `Helper/Data.php` retry logic

- **Monitor PHP Error Logs:**
  ```bash
  tail -f var/log/system.log
  tail -f var/log/exception.log
  ```
  Watch for any dynamic property warnings or errors.

### 4. Check for Deprecation Warnings

Enable error reporting in PHP to catch any remaining issues:

```php
// In index.php or your test script:
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

Run through all functionality and check for warnings.

---

## Optional: Additional Quality Improvements

These are **not required** for PHP 8.3/8.4 compatibility but improve code quality:

### Add Return Type Declarations

While not breaking in PHP 8.3/8.4, adding return types improves type safety:

- `Helper/Data.php`: Add return types to all methods (`:void`, `:string`, `:array`, etc.)
- All Observer `execute()` methods should return `:void`
- All Controller `execute()` methods should return `:\Magento\Framework\Controller\ResultInterface` or specific type

### Refactor ObjectManager Usage (Optional)

`Helper/Data.php` uses `ObjectManager::getInstance()` in 4 places (lines 77-78, 127-128, 147-149, 171-173). This is a Magento anti-pattern but NOT a PHP 8.3/8.4 compatibility issue. Consider refactoring to use proper dependency injection if time permits.

---

## Risk Assessment

- **Risk Level:** Low (changes are straightforward property declarations)
- **Breaking Changes:** None (only adds missing declarations, doesn't change logic)
- **Backwards Compatible:** Yes (PHP 8.1/8.2 still supported)
- **Testing Required:** Medium (manual testing of all webhook events recommended)

---

## Success Criteria

✅ All property declarations added
✅ composer.json updated to support PHP 8.3/8.4
✅ No PHP syntax errors when checked with PHP 8.3/8.4
✅ `composer validate` passes
✅ All observer events trigger without errors
✅ Admin panel export and retry features work
✅ No deprecation warnings in error logs
✅ Extension deploys successfully on PHP 8.3/8.4 environment
