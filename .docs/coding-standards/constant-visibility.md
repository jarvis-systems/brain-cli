---
name: "Constant Visibility Best Practices"
description: "PHP constant visibility rules and patterns to prevent runtime errors when constants are accessed across class boundaries"
type: "guide"
date: "2025-12-18"
version: "1.0.0"
---

# Constant Visibility Best Practices

## Introduction

Constant visibility in PHP determines whether a constant can be accessed from outside its defining class. Incorrect visibility leads to runtime fatal errors that crash the application. This guide establishes clear rules to prevent violations like the EVENT_TAB_NEXT/TAB_PREV incident in Task #24.

The core principle is simple: **if a constant is accessed from another class, it must be `public`**. If it's only used internally, it can be `private`.

## The Critical Rule: Public for Cross-Class Access

Any constant accessed from a different class **MUST** be declared as `public const`:

```php
// ✅ CORRECT
public const EVENT_TAB_NEXT = 'tab-next';
public const API_VERSION = '1.0.0';

// ❌ INCORRECT (causes runtime fatal error)
private const EVENT_TAB_NEXT = 'tab-next';
```

**Why?** PHP prohibits external access to private constants. When another class tries to access a private constant, you get:
```
Fatal error: Cannot access private const ClassName::CONSTANT_NAME
```

## Real-World Example: Task #24 Violation

This violation occurred in a real codebase and caused runtime failure:

**CommandLinePrompt.php** defined:
```php
// Line 53 - ORIGINALLY PRIVATE (WRONG)
private const EVENT_TAB_NEXT = 'tab-next';

// Line 58 - ORIGINALLY PRIVATE (WRONG)
private const EVENT_TAB_PREV = 'tab-previous';
```

**Screen.php** tried to access them externally:
```php
// Line 609 - VIOLATION: Cannot access private const
if (str_contains($command, CommandLinePrompt::EVENT_TAB_NEXT)) {
    // ...
}

// Line 612 - VIOLATION: Cannot access private const
if (str_contains($command, CommandLinePrompt::EVENT_TAB_PREV)) {
    // ...
}
```

**The Fix:**
```php
// Line 53 - CHANGED TO PUBLIC
public const EVENT_TAB_NEXT = 'tab-next';

// Line 58 - CHANGED TO PUBLIC
public const EVENT_TAB_PREV = 'tab-previous';
```

Now external access works without fatal errors.

## Visibility Levels: Three Options

PHP constant visibility has three levels:

### `public const` - Accessible Everywhere

Constants accessible from any class, anywhere in your codebase.

**Use when:**
- Constants represent domain events, signals, or API contracts
- Constants are configuration values needed across classes
- Constants define standard values for a feature (HTTP status codes, message types, etc.)
- Constants are part of the class's public API

**Example:**
```php
// Event fired from CommandLinePrompt, consumed by Screen
public const EVENT_TAB_NEXT = 'tab-next';

// Used internally:
$this->emit(self::EVENT_TAB_NEXT);

// Used externally:
if (str_contains($command, CommandLinePrompt::EVENT_TAB_NEXT)) { ... }
```

### `private const` - Internal Only

Constants accessible only within the defining class. Perfect for implementation details.

**Use when:**
- Constants represent magic numbers for internal logic
- Constants are implementation details not exposed to other classes
- Constants are used only for self:: references within the class
- Constants should never be accessed externally

**Example:**
```php
class Screen {
    // Internal buffer sizes - not for external use
    private const VALUE_MAX_LENGTH = 80;
    private const SHORT_VALUE_LENGTH = 40;

    public function render(string $value): string {
        if (strlen($value) > self::VALUE_MAX_LENGTH) {
            return substr($value, 0, self::SHORT_VALUE_LENGTH) . '...';
        }
        return $value;
    }
}

// This would FAIL (fatal error):
// Screen::VALUE_MAX_LENGTH;  // Cannot access private const
```

### `protected const` - Inheritance Only

Constants accessible from the defining class and its subclasses, but not external classes.

**Use when:**
- Constants should be shared across an inheritance hierarchy
- Constants provide baseline values that subclasses might override or reference
- Constants represent base behavior that child classes need to know about

**Example:**
```php
abstract class BasePrompt {
    // Available for subclasses
    protected const DEFAULT_TIMEOUT = 30;
}

class CommandLinePrompt extends BasePrompt {
    public function getTimeout(): int {
        return self::DEFAULT_TIMEOUT;  // ✅ Works - same or child class
    }
}

// This would FAIL (fatal error):
// $timeout = BasePrompt::DEFAULT_TIMEOUT;  // Cannot access protected const
```

## Decision Matrix: Which Visibility?

Use this decision tree to choose the right visibility for your constants:

| Question | Answer | Visibility |
|----------|--------|-----------|
| Will another class access this constant? | Yes | `public` |
| Will another class access this constant? | No | `private` |
| Will subclasses need this constant? | Yes | `protected` |
| Is this only for internal implementation? | Yes | `private` |
| Is this part of the class's API/contract? | Yes | `public` |
| Is this a magic number used only here? | Yes | `private` |

## Usage Patterns: `self::` vs `ClassName::`

Two ways to access constants exist, each with a specific purpose:

### Internal Access: `self::CONSTANT`

Use `self::` when accessing a constant **from within the same class or its children**:

```php
class CommandLinePrompt {
    public const EVENT_TAB_NEXT = 'tab-next';

    public function handleTab(): void {
        // Inside the class - use self::
        $this->emit(self::EVENT_TAB_NEXT);  // ✅ CORRECT
    }
}
```

**Advantages:**
- Clarity: Shows this is internal to the class
- Refactoring: Easier to find all uses via IDE
- Inheritance: Works automatically in child classes
- Static context: Works in static methods

### External Access: `ClassName::CONSTANT`

Use `ClassName::` when accessing a constant **from a different class**:

```php
class Screen {
    public function submit(): void {
        // Outside CommandLinePrompt class - use ClassName::
        if (str_contains($cmd, CommandLinePrompt::EVENT_TAB_NEXT)) {  // ✅ CORRECT
            handleTabNavigation();
        }
    }
}
```

**Advantages:**
- Clarity: Shows this is from an external class
- Flexibility: Can be swapped with different implementations
- Explicitness: Fully qualified reference

## Comprehensive Example

Here's a complete example showing best practices in context:

```php
<?php

namespace BrainCLI\Console\Lab\Prompts;

class CommandLinePrompt {
    // Public: This event signals tab navigation, consumed by other classes
    public const EVENT_TAB_NEXT = 'tab-next';
    public const EVENT_TAB_PREV = 'tab-previous';

    // Private: Internal constants for input processing
    private const INPUT_TIMEOUT = 30;
    private const MAX_BUFFER_SIZE = 4096;

    private string $buffer = '';

    public function processInput(string $raw): void {
        // Validate buffer size using private constant
        if (strlen($raw) > self::MAX_BUFFER_SIZE) {
            throw new \InvalidArgumentException('Input too large');
        }

        $this->buffer = $raw;

        // Emit event using public constant
        // (other classes will check for this event)
        if ($this->isTabPressed()) {
            $this->emit(self::EVENT_TAB_NEXT);
        }
    }

    private function emit(string $event): void {
        // Internal: Use self:: for private/public constants
        // Internal implementation details...
    }
}
```

**Usage from another class:**

```php
<?php

namespace BrainCLI\Console\Lab;

use BrainCLI\Console\Lab\Prompts\CommandLinePrompt;

class Screen {
    public function submit(string $command): void {
        // External: Use ClassName:: to access public constants
        if (str_contains($command, CommandLinePrompt::EVENT_TAB_NEXT)) {
            $this->handleTabNext();
        } elseif (str_contains($command, CommandLinePrompt::EVENT_TAB_PREV)) {
            $this->handleTabPrev();
        }
    }

    private function handleTabNext(): void {
        // Navigation logic...
    }
}
```

## Testing for Correct Visibility

### Unit Tests with Reflection

Use PHP's Reflection API to verify constant visibility in your tests:

```php
<?php

use PHPUnit\Framework\TestCase;
use BrainCLI\Console\Lab\Prompts\CommandLinePrompt;

class ConstantVisibilityTest extends TestCase {
    public function test_event_constants_are_public(): void {
        $reflection = new ReflectionClass(CommandLinePrompt::class);
        $constant = $reflection->getReflectionConstants()[0];

        // Verify constant is publicly accessible
        $this->assertTrue($constant->isPublic());

        // Verify we can access it
        $this->assertSame(
            'tab-next',
            CommandLinePrompt::EVENT_TAB_NEXT
        );
    }

    public function test_private_constants_not_accessible(): void {
        // This would FAIL with a fatal error if attempted
        // Reflection shows visibility without fatal error
        $reflection = new ReflectionClass(CommandLinePrompt::class);

        // Verify private constants exist but are not public
        foreach ($reflection->getReflectionConstants() as $const) {
            if (strpos($const->getName(), 'TIMEOUT') !== false) {
                $this->assertTrue($const->isPrivate());
            }
        }
    }
}
```

### Static Analysis Tools

Modern PHP tools can catch visibility violations before runtime:

**PHPStan** detects access to private/protected constants:
```bash
vendor/bin/phpstan analyse --level 5 src/
```

**Psalm** provides similar detection:
```bash
vendor/bin/psalm
```

Both tools catch violations like:
```
Cannot access private const CommandLinePrompt::EVENT_TAB_NEXT from Screen
```

## Common Mistakes to Avoid

### Mistake 1: Using `private` for Cross-Class Constants

```php
// ❌ WRONG - Private constant accessed externally
class ConfigReader {
    private const API_URL = 'https://api.example.com';
}

class Client {
    public function __construct() {
        // Fatal error: Cannot access private const
        $this->url = ConfigReader::API_URL;
    }
}
```

**Fix:** Use `public` if other classes need it:
```php
public const API_URL = 'https://api.example.com';
```

### Mistake 2: Mixing `self::` and `ClassName::` for Same Constant

```php
class CommandLinePrompt {
    public const EVENT_NAME = 'event';

    public function emit(): void {
        // ✅ CORRECT: Use self:: internally
        $this->emit(self::EVENT_NAME);
    }
}

class Screen {
    public function check(): void {
        // ✅ CORRECT: Use ClassName:: externally
        if ($event === CommandLinePrompt::EVENT_NAME) { ... }
    }
}

// ❌ WRONG: Mixing patterns in same context
class Confusing {
    public function mixed(): void {
        // Don't use ClassName:: inside CommandLinePrompt (use self::)
        // Don't use self:: inside Screen (use ClassName::)
    }
}
```

### Mistake 3: Forgetting to Migrate Private to Public

When refactoring and exposing internal constants:

```php
// ❌ WRONG: Just started using externally without changing visibility
private const OPTION_VALUE = 'value';

// Later... external class added:
// $option = ClassName::OPTION_VALUE;  // Runtime fatal error

// ✅ CORRECT: Change visibility first
public const OPTION_VALUE = 'value';
```

### Mistake 4: Using Hardcoded Strings Instead of Constants

```php
// ❌ WRONG: Magic strings instead of constants
class Screen {
    if (str_contains($cmd, 'tab-next')) {  // What is 'tab-next'? Where does it come from?
        // ...
    }
}

// ✅ CORRECT: Use constants for clarity and maintainability
class Screen {
    if (str_contains($cmd, CommandLinePrompt::EVENT_TAB_NEXT)) {
        // Clear: This is an event constant, centrally defined
    }
}
```

## Best Practices Summary

1. **Start with `private`**: Default to private unless the constant is genuinely needed elsewhere
2. **Make constants public only when necessary**: If another class accesses it, make it public
3. **Use `self::` internally**: Inside the defining class, use self::CONSTANT
4. **Use `ClassName::` externally**: From other classes, use ClassName::CONSTANT
5. **Document public constants**: Add docblock comments explaining what they represent
6. **Group related constants**: Keep event constants together, configuration together, etc.
7. **Test visibility**: Use Reflection in tests to verify constant visibility
8. **Use static analysis**: Enable PHPStan/Psalm to catch violations before runtime
9. **Review during code review**: Check constant visibility as part of PR review
10. **Name consistently**: Use UPPER_CASE for all constants (class constant convention)

## Key Takeaway

Constant visibility failures cause fatal runtime errors that crash applications. The rule is simple:

- **Cross-class access?** → `public const`
- **Internal only?** → `private const`
- **Inheritance hierarchy?** → `protected const`

When in doubt, use `private` and upgrade to `public` only when needed. This defensive approach catches mistakes early and keeps your API clean.

## References

- [PHP Manual: Class Constants](https://www.php.net/manual/en/language.oop5.constants.php)
- [PHP Manual: Visibility](https://www.php.net/manual/en/language.oop5.visibility.php)
- [PSR-12: Extended Coding Style Guide](https://www.php-fig.org/psr/psr-12/)
- [PHPStan: Constant Access](https://phpstan.org/)
- [Psalm: Type Coverage](https://psalm.dev/)

## Related Documentation

- Task #24: Constant Visibility Violations - Real-world incident analysis
- PHP Coding Standards: General guidelines for professional PHP code
- Unit Testing Guide: Testing constant accessibility with Reflection