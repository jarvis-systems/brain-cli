<?php

declare(strict_types=1);

namespace BrainCLI\Tests\Unit\Services\Docs;

use BrainCLI\Enums\Docs\CodeLanguage;
use BrainCLI\Services\Docs\LanguageDetector;
use PHPUnit\Framework\TestCase;

class LanguageDetectorTest extends TestCase
{
    protected LanguageDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new LanguageDetector();
    }

    public function test_declared_language_returned_directly(): void
    {
        $result = $this->detector->detect('php', '<?php echo "hello";');

        $this->assertSame(CodeLanguage::PHP, $result);
    }

    public function test_declared_alias_resolved(): void
    {
        $this->assertSame(CodeLanguage::JAVASCRIPT, $this->detector->detect('js', ''));
        $this->assertSame(CodeLanguage::TYPESCRIPT, $this->detector->detect('ts', ''));
        $this->assertSame(CodeLanguage::PYTHON, $this->detector->detect('py', ''));
        $this->assertSame(CodeLanguage::BASH, $this->detector->detect('sh', ''));
        $this->assertSame(CodeLanguage::YAML, $this->detector->detect('yml', ''));
        $this->assertSame(CodeLanguage::MARKDOWN, $this->detector->detect('md', ''));
        $this->assertSame(CodeLanguage::RUST, $this->detector->detect('rs', ''));
        $this->assertSame(CodeLanguage::GO, $this->detector->detect('golang', ''));
        $this->assertSame(CodeLanguage::CSHARP, $this->detector->detect('cs', ''));
        $this->assertSame(CodeLanguage::CPP, $this->detector->detect('c++', ''));
    }

    public function test_empty_code_returns_null(): void
    {
        $this->assertNull($this->detector->detect('', ''));
        $this->assertNull($this->detector->detect('', '   '));
    }

    public function test_detects_json(): void
    {
        $code = '{"key": "value", "number": 42}';

        $this->assertSame(CodeLanguage::JSON, $this->detector->detect('', $code));
    }

    public function test_detects_json_array(): void
    {
        $code = '[{"id": 1}, {"id": 2}]';

        $this->assertSame(CodeLanguage::JSON, $this->detector->detect('', $code));
    }

    public function test_detects_php_opening_tag(): void
    {
        $code = "<?php\necho 'Hello';";

        $this->assertSame(CodeLanguage::PHP, $this->detector->detect('', $code));
    }

    public function test_detects_php_namespace(): void
    {
        $code = "namespace App\\Services;\n\nclass Foo {}";

        $this->assertSame(CodeLanguage::PHP, $this->detector->detect('', $code));
    }

    public function test_detects_python(): void
    {
        $code = "def greet(name):\n    print(f'Hello {name}')";

        $this->assertSame(CodeLanguage::PYTHON, $this->detector->detect('', $code));
    }

    public function test_detects_python_import(): void
    {
        $code = "import os\nfrom pathlib import Path";

        $this->assertSame(CodeLanguage::PYTHON, $this->detector->detect('', $code));
    }

    public function test_detects_go_package(): void
    {
        $code = "package main\n\nimport \"fmt\"\n\nfunc main() {}";

        $this->assertSame(CodeLanguage::GO, $this->detector->detect('', $code));
    }

    public function test_detects_go_struct(): void
    {
        $code = "type User struct {\n    Name string\n    Age  int\n}";

        $this->assertSame(CodeLanguage::GO, $this->detector->detect('', $code));
    }

    public function test_detects_rust(): void
    {
        $code = "fn main() {\n    let mut x = 5;\n    println!(\"{}\", x);\n}";

        $this->assertSame(CodeLanguage::RUST, $this->detector->detect('', $code));
    }

    public function test_detects_typescript(): void
    {
        $code = "const greet = (name: string): void => {\n    console.log(name);\n};";

        $this->assertSame(CodeLanguage::TYPESCRIPT, $this->detector->detect('', $code));
    }

    public function test_detects_typescript_interface(): void
    {
        $code = "interface UserProps {\n    id: number;\n    active: boolean;\n}";

        $this->assertSame(CodeLanguage::TYPESCRIPT, $this->detector->detect('', $code));
    }

    public function test_detects_javascript(): void
    {
        $code = "const greet = (name) => {\n    console.log(name);\n};";

        $this->assertSame(CodeLanguage::JAVASCRIPT, $this->detector->detect('', $code));
    }

    public function test_detects_bash(): void
    {
        $code = "git clone https://github.com/user/repo.git\ncd repo\nnpm install";

        $this->assertSame(CodeLanguage::BASH, $this->detector->detect('', $code));
    }

    public function test_detects_bash_shebang(): void
    {
        $code = "#!/bin/bash\necho 'Hello'";

        $this->assertSame(CodeLanguage::BASH, $this->detector->detect('', $code));
    }

    public function test_detects_sql(): void
    {
        $code = "SELECT * FROM users WHERE status = 'active'";

        $this->assertSame(CodeLanguage::SQL, $this->detector->detect('', $code));
    }

    public function test_detects_dockerfile(): void
    {
        $code = "FROM node:18-alpine\nWORKDIR /app\nCOPY . .";

        $this->assertSame(CodeLanguage::DOCKERFILE, $this->detector->detect('', $code));
    }

    public function test_detects_yaml(): void
    {
        $code = "name: Build\non: push\njobs:\n  build:\n    runs-on: ubuntu-latest";

        $this->assertSame(CodeLanguage::YAML, $this->detector->detect('', $code));
    }

    public function test_detects_html(): void
    {
        $code = "<!DOCTYPE html>\n<html>\n<body>Hello</body>\n</html>";

        $this->assertSame(CodeLanguage::HTML, $this->detector->detect('', $code));
    }

    public function test_undetectable_returns_null(): void
    {
        $code = "some random text without clear language";

        $this->assertNull($this->detector->detect('', $code));
    }

    public function test_unknown_declared_language_returns_null(): void
    {
        $this->assertNull($this->detector->detect('brainfuck', ''));
    }

    public function test_ordering_typescript_before_javascript(): void
    {
        // This code has TypeScript-specific syntax
        $code = "const x: string = 'hello';\nconst y: number = 42;";

        $result = $this->detector->detect('', $code);

        $this->assertSame(CodeLanguage::TYPESCRIPT, $result);
    }
}
