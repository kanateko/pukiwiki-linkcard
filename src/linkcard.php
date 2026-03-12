<?php
/**
 * Linkcard プラグイン
 * 外部リンクからOGP情報を非同期で取得し、リンクカードとして表示する
 *
 * @version 1.1.0
 * @author kanateko
 * @link https://github.com/kanateko/pukiwiki-linkcard
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPLv3 or later
 */

// 定数
define('PLUGIN_LINKCARD_CACHE_DIR', CACHE_DIR . 'linkcard/');
define('PLUGIN_LINKCARD_CACHE_EXPIRE', 604800); // 7日間（秒）
define('PLUGIN_LINKCARD_IMAGE_WIDTH', 240);
define('PLUGIN_LINKCARD_IMAGE_HEIGHT', 126);
define('PLUGIN_LINKCARD_UA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36');
define('PLUGIN_LINKCARD_TIMEOUT', 10);
define('PLUGIN_LINKCARD_MAX_HTML_SIZE', 2097152); // 2MB
define('PLUGIN_LINKCARD_MAX_IMAGE_SIZE', 10485760); // 10MB
define('PLUGIN_LINKCARD_GC_PROBABILITY', 100); // 1/100
define('PLUGIN_LINKCARD_CACHE_MAX_DAYS', 30);

/**
 * ブロック型の呼び出し
 */
function plugin_linkcard_convert(string ...$args): string
{
    if (empty($args[0])) {
        return '<p class="plugin-linkcard-error">#linkcard Error: URLが指定されていません。</p>';
    }

    $url = trim($args[0]);

    // URLバリデーション
    if (!preg_match('/^https?:\/\//', $url)) {
        return '<p class="plugin-linkcard-error">#linkcard Error: 無効なURLです。</p>';
    }

    $linkcard = new Linkcard();
    return $linkcard->render($url);
}

/**
 * アクション型の呼び出し（OGP取得API ＆ 管理画面）
 */
function plugin_linkcard_action(): ?array
{
    $linkcard = new Linkcard();

    // POSTでurlが指定されている場合はOGP取得APIとして動作
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['url'])) {
        header('Content-Type: application/json; charset=UTF-8');

        $url = $_POST['url'] ?? '';
        $token = $_POST['token'] ?? '';

        // CSRFトークン検証
        if (!$linkcard->checkToken($token)) {
            header('HTTP/1.1 403 Forbidden');
            echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (empty($url) || !preg_match('/^https?:\/\//', $url)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid URL'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $data = $linkcard->fetchOgp($url);

        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // それ以外はすべて管理画面として扱う（GETリクエストやPOSTでの管理操作など）
    $linkcard->handleManage();
    exit;
}

/**
 * Linkcard クラス
 */
class Linkcard
{
    private static bool $assetsLoaded = false;

    /**
     * スケルトンUI付きのリンクカードHTMLを返す
     */
    public function render(string $url): string
    {
        $escapedUrl = htmlsc($url);
        $token = $this->getToken();
        $isDark = defined('PKWK_SKIN_DARK_THEME') && PKWK_SKIN_DARK_THEME ? ' plugin-linkcard--dark' : '';
        $assets = $this->loadAssets();

        return <<<EOD
        <div class="plugin-linkcard{$isDark}" data-url="{$escapedUrl}" data-token="{$token}">
            <div class="plugin-linkcard__skeleton">
                <div class="plugin-linkcard__skeleton-body">
                    <div class="plugin-linkcard__skeleton-title"></div>
                    <div class="plugin-linkcard__skeleton-desc"></div>
                    <div class="plugin-linkcard__skeleton-url"></div>
                </div>
                <div class="plugin-linkcard__skeleton-image"></div>
            </div>
        </div>
        {$assets}
        EOD;
    }

    /**
     * JS/CSSアセットの読み込み（初回のみ）
     */
    private function loadAssets(): string
    {
        if (self::$assetsLoaded) return '';
        self::$assetsLoaded = true;

        return '<script type="module">{js}</script>';
    }

    /**
     * セッションを開始し、CSRFトークンを取得
     */
    private function getToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (empty($_SESSION['plugin_linkcard_token'])) {
            $_SESSION['plugin_linkcard_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['plugin_linkcard_token'];
    }

    /**
     * トークンの検証
     */
    public function checkToken(string $token): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $sessionToken = $_SESSION['plugin_linkcard_token'] ?? '';
        return !empty($token) && hash_equals($sessionToken, $token);
    }

    /**
     * OGPデータの取得（キャッシュ確認 → 取得 → 保存）
     */
    public function fetchOgp(string $url): array
    {
        $this->garbageCollect();
        $this->ensureCacheDir();
        $hash = md5($url);
        $cacheFile = PLUGIN_LINKCARD_CACHE_DIR . $hash . '.json';
        $imageFile = PLUGIN_LINKCARD_CACHE_DIR . $hash . '.webp';

        // キャッシュ確認
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < PLUGIN_LINKCARD_CACHE_EXPIRE) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if ($cached) {
                $cached['image'] = file_exists($imageFile) ? $imageFile : '';
                return $cached;
            }
        }

        // OGP取得
        $html = $this->fetchHtml($url);
        if ($html === false) {
            return ['status' => 'error', 'message' => 'Failed to fetch URL'];
        }

        $ogp = $this->parseOgp($html, $url);

        // 画像の取得・リサイズ・WebP変換
        if (!empty($ogp['image_url'])) {
            $this->cacheImage($ogp['image_url'], $imageFile);
            $ogp['image'] = file_exists($imageFile) ? $imageFile : '';
        } else {
            $ogp['image'] = '';
        }
        unset($ogp['image_url']);

        // キャッシュ保存
        $ogp['cached_at'] = date('Y-m-d H:i:s');
        file_put_contents($cacheFile, json_encode($ogp, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $ogp;
    }

    /**
     * キャッシュディレクトリの作成と.htaccessの生成
     */
    private function ensureCacheDir(): void
    {
        if (!file_exists(PLUGIN_LINKCARD_CACHE_DIR)) {
            mkdir(PLUGIN_LINKCARD_CACHE_DIR, 0755, true);
        }

        $htaccess = PLUGIN_LINKCARD_CACHE_DIR . '.htaccess';
        if (!file_exists($htaccess)) {
            $content = <<<EOD
<Files ~ "\.(webp)$">
    <IfModule mod_authz_core.c>
        Require all granted
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Allow from all
    </IfModule>
</Files>
EOD;
            file_put_contents($htaccess, $content);
        }
    }

    /**
     * cURLでHTMLを取得
     */
    private function fetchHtml(string $url): string|false
    {
        if (!$this->isSafeUrl($url)) {
            return false;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => PLUGIN_LINKCARD_TIMEOUT,
            CURLOPT_USERAGENT => PLUGIN_LINKCARD_UA,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXFILESIZE => PLUGIN_LINKCARD_MAX_HTML_SIZE,
        ]);

        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($html === false || $httpCode >= 400) {
            return false;
        }

        // 文字コード変換
        $encoding = mb_detect_encoding($html, ['UTF-8', 'EUC-JP', 'SJIS', 'JIS', 'ASCII', 'ISO-8859-1'], true);
        if ($encoding && $encoding !== 'UTF-8') {
            $html = mb_convert_encoding($html, 'UTF-8', $encoding);
        }

        return $html;
    }

    /**
     * HTMLからOGPメタタグをパース
     */
    private function parseOgp(string $html, string $url): array
    {
        $result = [
            'status' => 'ok',
            'title' => '',
            'description' => '',
            'image_url' => '',
            'url' => $url,
            'site_name' => '',
        ];

        // DOMDocument でパース
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new \DOMXPath($doc);

        // OGPメタタグ
        $ogTags = [
            'og:title' => 'title',
            'og:description' => 'description',
            'og:image' => 'image_url',
            'og:image:secure_url' => 'image_url',
            'og:site_name' => 'site_name',
        ];

        foreach ($ogTags as $property => $key) {
            $nodes = $xpath->query("//meta[@property='{$property}']/@content");
            if ($nodes && $nodes->length > 0) {
                $value = $nodes->item(0)->nodeValue;
                if (!empty($value)) {
                    $result[$key] = $value;
                }
            }
        }

        // twitter:card のフォールバック
        if (empty($result['title'])) {
            $nodes = $xpath->query("//meta[@name='twitter:title']/@content");
            if ($nodes && $nodes->length > 0) $result['title'] = $nodes->item(0)->nodeValue;
        }
        if (empty($result['description'])) {
            $nodes = $xpath->query("//meta[@name='twitter:description']/@content");
            if ($nodes && $nodes->length > 0) $result['description'] = $nodes->item(0)->nodeValue;
        }
        if (empty($result['image_url'])) {
            $nodes = $xpath->query("//meta[@name='twitter:image']/@content");
            if ($nodes && $nodes->length > 0) $result['image_url'] = $nodes->item(0)->nodeValue;
        }

        // <title> フォールバック
        if (empty($result['title'])) {
            $titleNodes = $xpath->query('//title');
            if ($titleNodes && $titleNodes->length > 0) {
                $result['title'] = trim($titleNodes->item(0)->textContent);
            }
        }

        // <meta name="description"> フォールバック
        if (empty($result['description'])) {
            $nodes = $xpath->query("//meta[@name='description']/@content");
            if ($nodes && $nodes->length > 0) $result['description'] = $nodes->item(0)->nodeValue;
        }

        // 相対URLの絶対化
        if (!empty($result['image_url']) && !preg_match('/^https?:\/\//', $result['image_url'])) {
            if (str_starts_with($result['image_url'], '//')) {
                $result['image_url'] = 'https:' . $result['image_url'];
            } else {
                $parsed = parse_url($url);
                $base = $parsed['scheme'] . '://' . $parsed['host'];
                $result['image_url'] = $base . '/' . ltrim($result['image_url'], '/');
            }
        }

        // タイトルが取れなかった場合はURLをタイトルにする
        if (empty($result['title'])) {
            $result['title'] = $url;
        }

        // XSS対策
        $result['title'] = htmlsc($result['title']);
        $result['description'] = htmlsc($result['description']);
        $result['site_name'] = htmlsc($result['site_name']);

        return $result;
    }

    /**
     * 画像をダウンロードしてリサイズ・WebP変換してキャッシュ
     */
    private function cacheImage(string $imageUrl, string $savePath): void
    {
        if (!$this->isSafeUrl($imageUrl)) {
            return;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $imageUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => PLUGIN_LINKCARD_TIMEOUT,
            CURLOPT_USERAGENT => PLUGIN_LINKCARD_UA,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_MAXFILESIZE => PLUGIN_LINKCARD_MAX_IMAGE_SIZE,
        ]);

        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($imageData === false || $httpCode >= 400 || strlen($imageData) < 100) {
            return;
        }

        // GDで画像読み込み
        $src = @imagecreatefromstring($imageData);
        if ($src === false) {
            return;
        }

        $origWidth = imagesx($src);
        $origHeight = imagesy($src);
        $targetWidth = PLUGIN_LINKCARD_IMAGE_WIDTH;
        $targetHeight = PLUGIN_LINKCARD_IMAGE_HEIGHT;

        // アスペクト比を保持してリサイズ（カバーフィット）
        $srcRatio = $origWidth / $origHeight;
        $dstRatio = $targetWidth / $targetHeight;

        if ($srcRatio > $dstRatio) {
            // 横長：高さに合わせて幅をトリミング
            $resizeHeight = $targetHeight;
            $resizeWidth = (int) ($origWidth * ($targetHeight / $origHeight));
            $cropX = (int) (($resizeWidth - $targetWidth) / 2);
            $cropY = 0;
        } else {
            // 縦長：幅に合わせて高さをトリミング
            $resizeWidth = $targetWidth;
            $resizeHeight = (int) ($origHeight * ($targetWidth / $origWidth));
            $cropX = 0;
            $cropY = (int) (($resizeHeight - $targetHeight) / 2);
        }

        // リサイズ
        $resized = imagecreatetruecolor($resizeWidth, $resizeHeight);
        imagecopyresampled($resized, $src, 0, 0, 0, 0, $resizeWidth, $resizeHeight, $origWidth, $origHeight);

        // クロップ
        $cropped = imagecreatetruecolor($targetWidth, $targetHeight);
        imagecopy($cropped, $resized, 0, 0, $cropX, $cropY, $targetWidth, $targetHeight);

        // WebPで保存（品質80）
        imagewebp($cropped, $savePath, 80);

        imagedestroy($src);
        imagedestroy($resized);
        imagedestroy($cropped);
    }

    /**
     * URLが安全かどうか（SSRF対策：プライベートIPへのアクセス禁止）
     */
    private function isSafeUrl(string $url): bool
    {
        $parsed = parse_url($url);
        if (!isset($parsed['host'])) {
            return false;
        }

        $host = $parsed['host'];
        $ip = gethostbyname($host);

        if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
            // DNSの名前解決に失敗
            return false;
        }

        // プライベートIPアドレスとループバックアドレスをチェック
        $isPrivate = !filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        return !$isPrivate;
    }

    /**
     * 古いキャッシュの削除 (GC)
     */
    private function garbageCollect(): void
    {
        if (rand(1, PLUGIN_LINKCARD_GC_PROBABILITY) !== 1) {
            return;
        }

        if (!is_dir(PLUGIN_LINKCARD_CACHE_DIR)) {
            return;
        }

        $expire = time() - (PLUGIN_LINKCARD_CACHE_MAX_DAYS * 86400);
        foreach (glob(PLUGIN_LINKCARD_CACHE_DIR . '*') as $file) {
            if (is_file($file) && filemtime($file) < $expire) {
                @unlink($file);
            }
        }
    }

    /**
     * 管理画面の処理
     */
    public function handleManage(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $error = '';
        $success = '';

        // ログアウト処理
        if (isset($_GET['logout'])) {
            unset($_SESSION['plugin_linkcard_admin']);
            header('Location: ' . get_base_uri() . '?cmd=linkcard');
            exit;
        }

        // 認証処理
        if (isset($_POST['pass'])) {
            if (pkwk_login($_POST['pass'])) {
                $_SESSION['plugin_linkcard_admin'] = true;
                header('Location: ' . get_base_uri() . '?cmd=linkcard');
                exit;
            } else {
                $error = 'パスワードが違います。';
            }
        }

        $is_admin = $_SESSION['plugin_linkcard_admin'] ?? false;

        // キャッシュ削除処理
        if ($is_admin && isset($_POST['action']) && $_POST['action'] === 'clear_cache') {
            if ($this->checkToken($_POST['token'] ?? '')) {
                $this->clearCache();
                $success = 'キャッシュをすべて削除しました。';
            } else {
                $error = 'セッションがタイムアウトしました。もう一度お試しください。';
            }
        }

        $stats = $this->getCacheStats();
        $this->renderManagePage($is_admin, $stats, $error, $success);
    }

    /**
     * キャッシュ統計の取得
     */
    private function getCacheStats(): array
    {
        $count = 0;
        $size = 0;
        if (is_dir(PLUGIN_LINKCARD_CACHE_DIR)) {
            foreach (glob(PLUGIN_LINKCARD_CACHE_DIR . '*') as $file) {
                if (is_file($file) && basename($file) !== '.htaccess') {
                    $count++;
                    $size += filesize($file);
                }
            }
        }
        return [
            'count' => $count,
            'size' => $this->formatSize($size)
        ];
    }

    /**
     * キャッシュの全削除
     */
    private function clearCache(): void
    {
        if (is_dir(PLUGIN_LINKCARD_CACHE_DIR)) {
            foreach (glob(PLUGIN_LINKCARD_CACHE_DIR . '*') as $file) {
                if (is_file($file) && basename($file) !== '.htaccess') {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * サイズのフォーマット
     */
    private function formatSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }

    /**
     * 管理画面のレンダリング
     */
    private function renderManagePage(bool $is_admin, array $stats, string $error, string $success): void
    {
        $script = get_base_uri();
        $token = $this->getToken();
        $css = '<style>{css-manage}</style>'; // ビルド時に置換される

        $content = '';
        if (!$is_admin) {
            // ログインフォーム
            $alert = $error ? "<div class=\"lcm-alert lcm-alert--error\">{$error}</div>" : '';
            $content = <<<EOD
            <div class="lcm-card">
                {$alert}
                <form action="{$script}?cmd=linkcard" method="post" class="lcm-form">
                    <label for="pass">管理者パスワード</label>
                    <input type="password" name="pass" id="pass" required autofocus>
                    <button type="submit" class="lcm-btn lcm-btn--primary">ログイン</button>
                </form>
            </div>
            EOD;
        } else {
            // 管理機能
            $alert = $error ? "<div class=\"lcm-alert lcm-alert--error\">{$error}</div>" : '';
            if ($success) $alert .= "<div class=\"lcm-alert lcm-alert--success\">{$success}</div>";

            $content = <<<EOD
            <div class="lcm-stats">
                <div class="lcm-stats__item">
                    <div class="lcm-stats__item-label">キャッシュファイル数</div>
                    <div class="lcm-stats__item-value">{$stats['count']}</div>
                </div>
                <div class="lcm-stats__item">
                    <div class="lcm-stats__item-label">合計サイズ</div>
                    <div class="lcm-stats__item-value">{$stats['size']}</div>
                </div>
            </div>

            <div class="lcm-card">
                <h3>キャッシュ管理</h3>
                {$alert}
                <p>現在保存されているすべてのキャッシュ（JSONおよび画像データ）を削除します。<br>
                削除されたデータは次回のアクセス時に再度取得されます。</p>
                <form action="{$script}?cmd=linkcard" method="post" class="lcm-form" onsubmit="return confirm('本当にキャッシュをすべて削除しますか？');">
                    <input type="hidden" name="action" value="clear_cache">
                    <input type="hidden" name="token" value="{$token}">
                    <button type="submit" class="lcm-btn lcm-btn--danger">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                        キャッシュをすべて削除する
                    </button>
                </form>
            </div>

            <div style="text-align: right;">
                <a href="{$script}?cmd=linkcard&logout=1" style="font-size: 0.875rem; color: #64748b;">ログアウト</a>
            </div>
            EOD;
        }

        echo <<<EOD
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Linkcard キャッシュ管理</title>
    {$css}
</head>
<body style="background: #f1f5f9; margin: 0; padding: 0;">
    <div class="plugin-linkcard-manage">
        <header>
            <h1>Linkcard キャッシュ管理</h1>
            <p>PukiWiki Linkcard Plugin Management</p>
        </header>

        {$content}

        <footer style="margin-top: 3rem; text-align: center; color: #94a3b8; font-size: 0.75rem;">
            &copy; 2026 GamersWiki Linkcard Plugin
        </footer>
    </div>
</body>
</html>
EOD;
    }
}
