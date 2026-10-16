<?php

namespace Tests\Unit\Settings;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Coverage for custom *public* (guest web) templates served from
 * CUSTOM_TEMPLATES_FOLDER (companion to CustomTemplateAllowlistTest).
 *
 * The allowlist makes a custom public template selectable, but the guest view
 * resolves it through get_validated_template_path() and renders it through
 * render_template_view(). Both had to honour the custom folder:
 *
 *  - get_validated_template_path() only looked in application/views, so an
 *    allowlisted custom public template failed validate_file_in_directory()
 *    and the guest page returned a 500.
 *  - render_template_view() included custom files from a plain function, so
 *    $this was undefined; templates based on InvoicePlane_Web call
 *    $this->layout->load_view() and crashed.
 *
 * CUSTOM_TEMPLATES_FOLDER is a constant defined once at boot, so every case
 * boots the real kernel in its own PHP subprocess with the ipconfig values in
 * $_ENV (as Dotenv leaves them), and reports the outcome as JSON.
 */
class CustomPublicTemplateTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir() . '/ip-custom-tpl-' . bin2hex(random_bytes(6));
        mkdir($this->folder . '/invoice_templates/public', 0755, true);
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->folder, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->folder);
    }

    #[Test]
    public function it_resolves_an_allowlisted_custom_public_template_from_the_custom_folder(): void
    {
        /* Arrange */
        file_put_contents($this->folder . '/invoice_templates/public/Custom_Web.php', 'custom');

        /* Act */
        $result = $this->runProbe('echo json_encode(get_validated_template_path("Custom_Web", "invoice", "public", "InvoicePlane_Web"));');

        /* Assert */
        self::assertSame(
            ['path' => 'invoice_templates/public/Custom_Web.php', 'name' => 'Custom_Web'],
            $result,
            'An allowlisted template that exists in CUSTOM_TEMPLATES_FOLDER must resolve instead of raising a 500.'
        );
    }

    #[Test]
    public function it_renders_a_custom_template_with_access_to_the_controller(): void
    {
        /* Arrange */
        file_put_contents(
            $this->folder . '/invoice_templates/public/Custom_Web.php',
            '<?php echo $this->layout->load_view("layout/alerts") . "|" . $greeting;'
        );

        /* Act */
        $result = $this->runProbe(
            'echo json_encode(render_template_view("invoice_templates/public/Custom_Web", ["greeting" => "hello"], true));'
        );

        /* Assert */
        self::assertSame(
            'layout:layout/alerts|hello',
            $result,
            'A custom template must be able to use $this and its data, like a view loaded by $CI->load->view().'
        );
    }

    #[Test]
    public function it_keeps_the_built_in_template_when_no_custom_file_exists(): void
    {
        /* Arrange: the custom folder is configured but holds no InvoicePlane_Web.php */

        /* Act */
        $result = $this->runProbe('echo json_encode(get_validated_template_path("InvoicePlane_Web", "invoice", "public", "InvoicePlane_Web"));');

        /* Assert */
        self::assertSame(
            ['path' => 'invoice_templates/public/InvoicePlane_Web.php', 'name' => 'InvoicePlane_Web'],
            $result,
            'Without a custom file the built-in template from application/views must still be used.'
        );
    }

    /**
     * Boot the real kernel with CUSTOM_TEMPLATES_FOLDER pointing at the test
     * folder, provide the minimal CodeIgniter seams the helpers need, run
     * $code and decode its JSON output.
     */
    private function runProbe(string $code): mixed
    {
        $env = [
            'CUSTOM_TEMPLATES_FOLDER'         => $this->folder,
            'CUSTOM_INVOICE_TEMPLATES_PUBLIC' => 'Custom_Web',
        ];

        $script = '';
        foreach ($env as $key => $value) {
            $script .= sprintf('$_ENV[%s] = %s;', var_export($key, true), var_export($value, true));
        }

        $script .= 'require ' . var_export(ROOT_PATH . '/bootstrap/kernel.php', true) . ';'
            . 'if ( ! function_exists("log_message")) { function log_message($level, $message) {} }'
            . 'if ( ! function_exists("show_error")) { function show_error($message, $status = 500) { echo json_encode(["show_error" => $status]); exit(0); } }'
            . 'if ( ! function_exists("get_instance")) { function &get_instance() { return $GLOBALS["ci"]; } }'
            . 'require_once APPPATH . "helpers/file_security_helper.php";'
            . 'require_once APPPATH . "helpers/template_helper.php";'
            . '$GLOBALS["ci"] = new class {'
            . '  public object $load; public object $mdl_templates; public object $output; public object $layout;'
            . '  public function __construct() {'
            . '    $this->load = new class { public function helper($n) {} public function model($n) {} };'
            . '    $this->mdl_templates = new class {'
            . '      public function get_invoice_templates($scope) { return ["InvoicePlane_Web", "Custom_Web"]; }'
            . '      public function get_quote_templates($scope) { return ["InvoicePlane_Web"]; }'
            . '    };'
            . '    $this->output = new class { public function append_output($o) { echo $o; } };'
            . '    $this->layout = new class { public function load_view($v) { return "layout:" . $v; } };'
            . '  }'
            . '};'
            . $code;

        $output = [];
        $status = 0;
        exec(PHP_BINARY . ' -r ' . escapeshellarg($script) . ' 2>&1', $output, $status);

        self::assertSame(0, $status, "Probe subprocess must exit cleanly:\n" . implode("\n", $output));

        return json_decode(implode("\n", $output), true, flags: JSON_THROW_ON_ERROR);
    }
}
