<?php
/**
 * View renderer with a shared layout.
 */
declare(strict_types=1);

final class View
{
    /** Values set by a template and read by the layout (e.g. extraScripts). */
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    /**
     * Read (and clear) a shared value. Consumed values never leak into the
     * next render inside the same request.
     */
    public static function pull(string $key, mixed $default = null): mixed
    {
        if (!array_key_exists($key, self::$shared)) {
            return $default;
        }
        $v = self::$shared[$key];
        unset(self::$shared[$key]);
        return $v;
    }

    public function render(string $template, array $data = [], ?string $layout = 'layout/main'): void
    {
        $data['content'] = $this->partial($template, $data);
        if ($layout !== null) {
            echo $this->partial($layout, $data);
        }
        self::$shared = [];
    }

    public function partial(string $template, array $data = []): string
    {
        $file = BASE_PATH . '/app/views/' . $template . '.php';
        if (!is_file($file)) {
            return '<div class="alert alert-danger">View not found: ' . e($template) . '</div>';
        }
        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }
}
