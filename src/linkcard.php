<?php
/**
 * Linkcard プラグイン
 * 外部リンクからOGP情報を非同期で取得し、リンクカードとして表示する
 *
 * @version 1.0.1
 * @author kanateko
 * @link https://github.com/kanateko/pukiwiki-linkcard
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPLv3 or later
 */

// 定数
define('PLUGIN_LINKCARD_CACHE_DIR', CACHE_DIR . 'linkcard/');
define('PLUGIN_LINKCARD_CACHE_EXPIRE', 604800); // 7日間（秒）
define('PLUGIN_LINKCARD_IMAGE_WIDTH', 240);
define('PLUGIN_LINKCARD_IMAGE_HEIGHT', 126);
define('PLUGIN_LINKCARD_UA', 'Mozilla/5.0 (compatible; PukiWiki LinkCard;)');
define('PLUGIN_LINKCARD_TIMEOUT', 10);

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
 * アクション型の呼び出し（OGP取得API）
 */
function plugin_linkcard_action(): array
{
    header('Content-Type: application/json; charset=UTF-8');

    // POSTリクエストのみ許可
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['status' => 'error', 'message' => 'POST method required'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $url = $_POST['url'] ?? '';

    if (empty($url) || !preg_match('/^https?:\/\//', $url)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid URL'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $linkcard = new Linkcard();
    $data = $linkcard->fetchOgp($url);

    echo json_encode($data, JSON_UNESCAPED_UNICODE);
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
        $isDark = defined('PKWK_SKIN_DARK_THEME') && PKWK_SKIN_DARK_THEME ? ' plugin-linkcard--dark' : '';
        $assets = $this->loadAssets();

        return <<<EOD
        <div class="plugin-linkcard{$isDark}" data-url="{$escapedUrl}">
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
     * OGPデータの取得（キャッシュ確認 → 取得 → 保存）
     */
    public function fetchOgp(string $url): array
    {
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
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $imageUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => PLUGIN_LINKCARD_TIMEOUT,
            CURLOPT_USERAGENT => PLUGIN_LINKCARD_UA,
            CURLOPT_SSL_VERIFYPEER => true,
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
}
