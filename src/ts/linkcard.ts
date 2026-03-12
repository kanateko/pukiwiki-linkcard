// Linkcard プラグイン - フロントエンドスクリプト
// 非同期でOGPデータを取得し、リンクカードを表示する

interface OgpData {
  status: string;
  title: string;
  description?: string;
  image?: string;
  site_name?: string;
}

(() => {
  const MAX_CONCURRENT = 5;

  // CSS動的挿入
  if (!document.querySelector('[data-linkcard-style]')) {
    const style = document.createElement('style');
    style.setAttribute('data-linkcard-style', '');
    style.textContent = '{css}';
    document.head.appendChild(style);
  }

  /**
   * OGPデータをPOSTで取得
   */
  async function fetchOgpData(url: string, token: string): Promise<OgpData> {
    const params = new URLSearchParams();
    params.set('url', url);
    params.set('token', token);

    const response = await fetch('?plugin=linkcard', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString(),
    });

    if (!response.ok) throw new Error('HTTP ' + response.status);
    return response.json();
  }

  /**
   * リンクカードDOMを構築
   */
  function buildCard(container: HTMLElement, data: OgpData): void {
    const url = container.dataset.url || '';

    // ドメイン名の抽出
    let domain = '';
    try {
      domain = new URL(url).hostname;
    } catch (e) {
      domain = url;
    }

    const hasImage = data.image && data.image.length > 0;

    container.innerHTML =
      '<a href="' + escapeHtml(url) + '" class="plugin-linkcard__link" target="_blank" rel="noopener noreferrer">' +
        '<div class="plugin-linkcard__body">' +
          '<div class="plugin-linkcard__title">' + escapeHtml(data.title) + '</div>' +
          (data.description ? '<div class="plugin-linkcard__desc">' + escapeHtml(truncate(data.description, 120)) + '</div>' : '') +
          '<div class="plugin-linkcard__meta">' +
            '<span class="plugin-linkcard__favicon"><img src="https://www.google.com/s2/favicons?sz=16&domain=' + encodeURIComponent(domain) + '" alt="" width="16" height="16" loading="lazy"></span>' +
            '<span class="plugin-linkcard__domain">' + escapeHtml(data.site_name || domain) + '</span>' +
          '</div>' +
        '</div>' +
        (hasImage ? '<div class="plugin-linkcard__image"><img src="' + escapeHtml(data.image!) + '" alt="" loading="lazy"></div>' : '') +
      '</a>';

    container.classList.add('plugin-linkcard--loaded');
  }

  /**
   * エラー時のフォールバック表示
   */
  function buildFallback(container: HTMLElement): void {
    const url = container.dataset.url || '';
    container.innerHTML =
      '<a href="' + escapeHtml(url) + '" class="plugin-linkcard__link plugin-linkcard__link--fallback" target="_blank" rel="noopener noreferrer">' +
        '<span class="plugin-linkcard__fallback-icon">\u{1F517}</span>' +
        '<span class="plugin-linkcard__fallback-url">' + escapeHtml(url) + '</span>' +
      '</a>';
    container.classList.add('plugin-linkcard--loaded');
  }

  /**
   * HTMLエスケープ
   */
  function escapeHtml(str: string): string {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  /**
   * 文字列の切り詰め
   */
  function truncate(str: string, max: number): string {
    return str.length > max ? str.slice(0, max) + '\u2026' : str;
  }

  /**
   * 同時実行数を制限して処理
   */
  async function processWithLimit(cards: HTMLElement[], limit: number): Promise<void> {
    let index = 0;

    async function next() {
      while (index < cards.length) {
        const card = cards[index++];
        const url = card.dataset.url;
        const token = card.dataset.token || '';
        if (!url) continue;

        try {
          const data = await fetchOgpData(url, token);
          if (data.status === 'ok') {
            buildCard(card, data);
          } else {
            buildFallback(card);
          }
        } catch (e) {
          buildFallback(card);
        }
      }
    }

    const workers = Array.from({ length: Math.min(limit, cards.length) }, () => next());
    await Promise.all(workers);
  }

  /**
   * 初期化
   */
  function init(): void {
    const cards = Array.from(
      document.querySelectorAll<HTMLElement>('.plugin-linkcard[data-url]:not(.plugin-linkcard--loaded)')
    );
    if (cards.length === 0) return;
    processWithLimit(cards, MAX_CONCURRENT);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
