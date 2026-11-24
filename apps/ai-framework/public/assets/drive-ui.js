export function initCloudDrive() {
  'use strict';

  const stateEl = document.getElementById('drive-state');
  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  let csrfToken = csrfMeta ? csrfMeta.getAttribute('content') || '' : '';

  const state = stateEl ? JSON.parse(stateEl.textContent || '{}') : {
    view: 'my-drive',
    folderId: null,
    items: [],
    breadcrumbs: [],
    storage: { used_bytes: 0, quota_bytes: 16106127360 },
    q: '',
    type: 'all',
    sort: 'name',
    dir: 'asc',
    framework: ''
  };

  const INLINE_IMAGE_MIMES = new Set(['image/png', 'image/jpeg', 'image/webp', 'image/gif']);

  let selectedId = null;
  let viewMode = 'list';
  try { viewMode = localStorage.getItem('clouddrive_view_mode') || 'list'; } catch (_) {}
  let activeMenuItemId = null;
  let searchTimer = null;

  // DOM refs
  const sidebar = document.getElementById('app-sidebar');
  const scrim = document.getElementById('drawer-scrim');
  const menuToggleBtn = document.getElementById('menu-toggle-btn');
  const newBtn = document.getElementById('new-btn');
  const mobileFab = document.getElementById('mobile-fab');
  const newMenu = document.getElementById('new-menu');
  const itemMenu = document.getElementById('item-menu');
  const fileUploadInput = document.getElementById('file-upload-input');
  const searchInput = document.getElementById('search-input');
  const searchClearBtn = document.getElementById('search-clear-btn');
  const viewListBtn = document.getElementById('view-list-btn');
  const viewGridBtn = document.getElementById('view-grid-btn');
  const detailsToggleBtn = document.getElementById('details-toggle-btn');
  const detailsPanel = document.getElementById('details-panel');
  const detailsCloseBtn = document.getElementById('details-close-btn');
  const detailsContent = document.getElementById('details-content');
  const selectionBar = document.getElementById('selection-bar');
  const selectionLabel = document.getElementById('selection-label');
  const filesListWrap = document.getElementById('files-list-wrap');
  const filesGridWrap = document.getElementById('files-grid-wrap');
  const filesTbody = document.getElementById('files-tbody');
  const emptyStateEl = document.getElementById('empty-state');
  const sortSelect = document.getElementById('sort-select');
  const sortDirBtn = document.getElementById('sort-dir-btn');
  const workspaceMain = document.getElementById('main-content');
  const dropOverlay = document.getElementById('drop-overlay');
  const toastContainer = document.getElementById('toast-container');

  // Modals
  const modalBackdrop = document.getElementById('modal-backdrop');
  const modalTitle = document.getElementById('modal-title');
  const modalBody = document.getElementById('modal-body');
  const modalFooter = document.getElementById('modal-footer');

  // Preview Modal
  const previewBackdrop = document.getElementById('preview-backdrop');
  const previewTitle = document.getElementById('preview-title');
  const previewBody = document.getElementById('preview-body');
  const previewDownloadLink = document.getElementById('preview-download-link');
  const previewCloseBtn = document.getElementById('preview-close-btn');

  function esc(str) {
    return String(str ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function formatBytes(bytes, isFolder = false) {
    if (isFolder) return '—';
    const n = Number(bytes ?? 0);
    if (n <= 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;
    let val = n;
    while (val >= 1024 && i < units.length - 1) {
      val /= 1024;
      i++;
    }
    return (i === 0 ? val.toFixed(0) : val.toFixed(1)) + ' ' + units[i];
  }

  function formatDate(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return iso;
    return `${d.getFullYear()}/${String(d.getMonth() + 1).padStart(2, '0')}/${String(d.getDate()).padStart(2, '0')}`;
  }

  function itemIconSvg(item) {
    if (Number(item.is_folder) === 1) {
      return '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" fill="#444746"><path d="M10 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>';
    }
    const ext = (item.extension || '').toLowerCase();
    const mime = (item.mime_type || '').toLowerCase();
    if (mime.startsWith('image/') || ['png', 'jpg', 'jpeg', 'webp', 'gif'].includes(ext)) {
      return '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" fill="#d93025"><path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/></svg>';
    }
    if (ext === 'pdf' || mime === 'application/pdf') {
      return '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" fill="#ea4335"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm1 7V3.5L19.5 9H15zM8 14h8v2H8v-2zm0 4h5v2H8v-2z"/></svg>';
    }
    if (['csv', 'xls', 'xlsx'].includes(ext)) {
      return '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" fill="#0f9d58"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-8 14H5v-4h6v4zm0-6H5V7h6v4zm8 6h-6v-4h6v4zm0-6h-6V7h6v4z"/></svg>';
    }
    if (['md', 'txt', 'doc', 'docx'].includes(ext)) {
      return '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" fill="#1a73e8"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>';
    }
    return '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" fill="#5f6368"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13z"/></svg>';
  }

  function showToast(msg) {
    if (!toastContainer) return;
    const el = document.createElement('div');
    el.className = 'toast';
    el.setAttribute('role', 'status');
    el.textContent = msg;
    toastContainer.appendChild(el);
    setTimeout(() => el.remove(), 3400);
  }

  async function apiRequest(url, options = {}, retried = false) {
    const headers = Object.assign({ 'Accept': 'application/json' }, options.headers || {});
    if (options.method && options.method !== 'GET') {
      headers['X-CSRF-Token'] = csrfToken;
    }
    const res = await fetch(url, Object.assign({}, options, { headers, credentials: 'same-origin' }));
    const nextToken = res.headers.get('X-CSRF-Token');
    if (nextToken) {
      csrfToken = nextToken;
      if (csrfMeta) csrfMeta.setAttribute('content', nextToken);
    }
    // Auto-recover once if CSRF token expired/desynced
    if (res.status === 403 && !retried && options.method && options.method !== 'GET') {
      const refreshRes = await fetch('/api/storage', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const fresh = refreshRes.headers.get('X-CSRF-Token');
      if (fresh) {
        csrfToken = fresh;
        if (csrfMeta) csrfMeta.setAttribute('content', fresh);
        return apiRequest(url, options, true);
      }
    }
    const contentType = res.headers.get('Content-Type') || '';
    const data = contentType.includes('application/json') ? await res.json() : null;
    if (!res.ok) {
      throw new Error((data && (data.error || data.message)) || `エラーが発生しました (${res.status})`);
    }
    return data;
  }

  function closeDrawer() {
    if (sidebar) sidebar.classList.remove('open');
    if (scrim) scrim.classList.remove('open');
    if (menuToggleBtn) menuToggleBtn.setAttribute('aria-expanded', 'false');
  }

  function closeMenus() {
    if (newMenu) newMenu.classList.remove('open');
    if (itemMenu) itemMenu.classList.remove('open');
    if (newBtn) newBtn.setAttribute('aria-expanded', 'false');
  }

  function closeModal() {
    if (modalBackdrop) modalBackdrop.classList.remove('open');
  }

  function openModal(title, bodyEl, buttons, onSubmit = null) {
    if (!modalBackdrop) return;
    modalTitle.textContent = title;
    if (onSubmit) {
      const form = document.createElement('form');
      form.appendChild(bodyEl);
      form.addEventListener('submit', (e) => {
        e.preventDefault();
        onSubmit();
      });
      modalBody.replaceChildren(form);
    } else {
      modalBody.replaceChildren(bodyEl);
    }
    modalFooter.replaceChildren();
    buttons.forEach((b) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = b.className || 'btn-secondary';
      btn.textContent = b.label;
      if (b.id) btn.id = b.id;
      if (b.disabled) btn.disabled = true;
      btn.addEventListener('click', b.onClick);
      modalFooter.appendChild(btn);
    });
    modalBackdrop.classList.add('open');
    const firstInput = modalBody.querySelector('input:not([type="radio"]), select, textarea, button');
    if (firstInput) firstInput.focus();
  }

  function updateStorageUI(storage) {
    if (!storage) return;
    const fill = document.getElementById('storage-bar-fill');
    const text = document.getElementById('storage-text');
    const used = Number(storage.used_bytes || 0);
    const quota = Number(storage.quota_bytes || 16106127360);
    const pct = Math.min(100, Math.max(used > 0 ? 1 : 0, (used / quota) * 100));
    if (fill) fill.style.width = pct.toFixed(1) + '%';
    if (text) text.textContent = `${formatBytes(used, false)} / 15 GB を使用中`;
  }

  function getSelectedItem() {
    return (state.items || []).find((i) => Number(i.id) === Number(selectedId)) || null;
  }

  function syncSelectionVisuals() {
    document.querySelectorAll('[data-item-id].drive-row, [data-item-id].folder-card, [data-item-id].file-card').forEach((el) => {
      el.classList.toggle('selected', Number(el.getAttribute('data-item-id')) === Number(selectedId));
    });
    updateSelectionBar();
  }

  function updateSelectionBar() {
    if (!selectionBar) return;
    const item = getSelectedItem();
    if (!item) {
      selectionBar.classList.remove('visible');
      return;
    }
    selectionBar.classList.add('visible');
    selectionLabel.textContent = `選択中: ${item.name}`;

    const isTrashed = Number(item.is_trashed) === 1;
    const dlBtn = document.getElementById('sel-download-btn');
    const starBtn = document.getElementById('sel-star-btn');
    const shareBtn = document.getElementById('sel-share-btn');
    const renameBtn = document.getElementById('sel-rename-btn');
    const moveBtn = document.getElementById('sel-move-btn');
    const trashBtn = document.getElementById('sel-trash-btn');
    const restoreBtn = document.getElementById('sel-restore-btn');

    if (dlBtn) dlBtn.classList.toggle('hidden', Number(item.is_folder) === 1 || isTrashed);
    if (starBtn) {
      starBtn.classList.toggle('hidden', isTrashed);
      starBtn.textContent = Number(item.is_starred) === 1 ? '★ スター解除' : '☆ スターを付ける';
    }
    if (shareBtn) shareBtn.classList.toggle('hidden', isTrashed);
    if (renameBtn) renameBtn.classList.toggle('hidden', isTrashed);
    if (moveBtn) moveBtn.classList.toggle('hidden', isTrashed);
    if (restoreBtn) restoreBtn.classList.toggle('hidden', !isTrashed);
    if (trashBtn) trashBtn.textContent = isTrashed ? '完全に削除' : 'ゴミ箱に移動';
  }

  function renderItems() {
    if (!filesTbody || !filesGridWrap) return;
    const items = state.items || [];

    if (items.length === 0) {
      filesListWrap.classList.add('hidden');
      filesGridWrap.classList.add('hidden');
      if (emptyStateEl) emptyStateEl.classList.remove('hidden');
      updateSelectionBar();
      return;
    }

    if (emptyStateEl) emptyStateEl.classList.add('hidden');

    const isGrid = viewMode === 'grid';
    filesListWrap.classList.toggle('hidden', isGrid);
    filesGridWrap.classList.toggle('hidden', !isGrid);
    if (viewListBtn) viewListBtn.setAttribute('aria-pressed', String(!isGrid));
    if (viewGridBtn) viewGridBtn.setAttribute('aria-pressed', String(isGrid));

    filesTbody.innerHTML = items.map((item) => {
      const isFolder = Number(item.is_folder) === 1;
      const isTrashed = Number(item.is_trashed) === 1;
      const isSelected = Number(item.id) === Number(selectedId);
      const href = isTrashed ? `#trash-${item.id}` : (isFolder ? `/drive/folders/${item.id}` : `#preview-${item.id}`);
      const starBadge = Number(item.is_starred) === 1 ? '<span class="badge-inline badge-star" title="スター付き" aria-label="スター付き">★</span>' : '';
      const shareBadge = Number(item.is_shared) === 1 ? '<span class="badge-inline" title="共有中" aria-label="共有中"><svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg></span>' : '';
      const subText = `${formatDate(item.updated_at)} ・ ${isFolder ? 'フォルダ' : formatBytes(item.size_bytes, false)}`;

      return `<tr class="drive-row${isSelected ? ' selected' : ''}" data-item-id="${item.id}" data-is-folder="${isFolder ? 1 : 0}" data-is-trashed="${isTrashed ? 1 : 0}">
        <td class="col-name">
          <div class="item-name-cell">
            ${itemIconSvg(item)}
            <div class="item-text-stack">
              <a class="item-link" href="${href}" data-action="open" data-item-id="${item.id}" data-is-trashed="${isTrashed ? 1 : 0}">${esc(item.name)}${starBadge}${shareBadge}</a>
              <span class="item-sub-mobile">${esc(subText)}</span>
            </div>
          </div>
        </td>
        <td class="col-owner">${esc(item.owner_name)}</td>
        <td class="col-updated">${esc(formatDate(item.updated_at))}</td>
        <td class="col-size">${esc(formatBytes(item.size_bytes, isFolder))}</td>
        <td class="col-actions row-actions-cell">
          <button type="button" class="kebab-btn" data-action="menu" data-item-id="${item.id}" aria-label="${esc(item.name)} の操作メニュー">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg>
          </button>
        </td>
      </tr>`;
    }).join('');

    const folders = items.filter((i) => Number(i.is_folder) === 1);
    const files = items.filter((i) => Number(i.is_folder) === 0);
    let gridHtml = '';
    if (folders.length > 0) {
      gridHtml += '<h2 class="grid-section-title">フォルダ</h2><div class="folder-grid">';
      gridHtml += folders.map((item) => {
        const isTrashed = Number(item.is_trashed) === 1;
        const isSelected = Number(item.id) === Number(selectedId);
        const href = isTrashed ? `#trash-${item.id}` : `/drive/folders/${item.id}`;
        return `<div class="folder-card${isSelected ? ' selected' : ''}" data-item-id="${item.id}" data-is-folder="1" data-is-trashed="${isTrashed ? 1 : 0}">
          <a class="folder-card-main" href="${href}" data-action="open" data-item-id="${item.id}" data-is-trashed="${isTrashed ? 1 : 0}">
            ${itemIconSvg(item)}
            <span>${esc(item.name)}</span>
          </a>
          <button type="button" class="kebab-btn" data-action="menu" data-item-id="${item.id}" aria-label="${esc(item.name)} の操作メニュー">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg>
          </button>
        </div>`;
      }).join('');
      gridHtml += '</div>';
    }
    if (files.length > 0) {
      gridHtml += '<h2 class="grid-section-title">ファイル</h2><div class="file-grid">';
      gridHtml += files.map((item) => {
        const isTrashed = Number(item.is_trashed) === 1;
        const isSelected = Number(item.id) === Number(selectedId);
        const isImg = !isTrashed && INLINE_IMAGE_MIMES.has((item.mime_type || '').toLowerCase());
        const thumb = isImg
          ? `<img src="/api/files/${item.id}/preview" alt="${esc(item.name)}" width="220" height="132" loading="lazy">`
          : `${itemIconSvg(item)}<span class="storage-text">${esc((item.extension || 'file').toUpperCase())}</span>`;
        return `<article class="file-card${isSelected ? ' selected' : ''}" data-item-id="${item.id}" data-is-folder="0" data-is-trashed="${isTrashed ? 1 : 0}">
          <div class="file-card-header">
            <a class="file-card-title" href="#preview-${item.id}" data-action="open" data-item-id="${item.id}" data-is-trashed="${isTrashed ? 1 : 0}">
              ${itemIconSvg(item)}
              <span>${esc(item.name)}</span>
            </a>
            <button type="button" class="kebab-btn" data-action="menu" data-item-id="${item.id}" aria-label="${esc(item.name)} の操作メニュー">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg>
            </button>
          </div>
          <a class="file-card-thumb" href="#preview-${item.id}" data-action="open" data-item-id="${item.id}" data-is-trashed="${isTrashed ? 1 : 0}" aria-label="${esc(item.name)} をプレビュー">${thumb}</a>
          <div class="file-card-meta">
            <span>${esc(formatDate(item.updated_at))}</span>
            <span>${esc(formatBytes(item.size_bytes, false))}</span>
          </div>
        </article>`;
      }).join('');
      gridHtml += '</div>';
    }
    filesGridWrap.innerHTML = gridHtml;
    updateSelectionBar();
  }

  async function refreshItems() {
    const params = new URLSearchParams();
    params.set('view', state.view || 'my-drive');
    if (state.folderId) params.set('folder_id', String(state.folderId));
    if (state.q) params.set('q', state.q);
    if (state.type && state.type !== 'all') params.set('type', state.type);
    if (state.sort) params.set('sort', state.sort);
    if (state.dir) params.set('dir', state.dir);

    try {
      const data = await apiRequest('/api/items?' + params.toString());
      state.items = data.items || [];
      if (data.storage) {
        state.storage = data.storage;
        updateStorageUI(data.storage);
      }
      if (selectedId && !state.items.some((i) => Number(i.id) === Number(selectedId))) {
        selectedId = null;
      }
      renderItems();
    } catch (err) {
      showToast(err.message);
    }
  }

  async function openDetails(id) {
    if (!detailsPanel || !detailsContent) return;
    detailsPanel.classList.add('open');
    if (detailsToggleBtn) detailsToggleBtn.setAttribute('aria-pressed', 'true');
    if (!id) {
      detailsContent.innerHTML = '<p class="storage-text">ファイルまたはフォルダを選択すると、詳細情報とアクティビティ履歴が表示されます。</p>';
      return;
    }
    try {
      const data = await apiRequest(`/api/items/${id}`);
      const item = data.item;
      const isTrashed = Number(item.is_trashed) === 1;
      const activities = data.activities || [];
      const wrap = document.createElement('div');
      wrap.innerHTML = `
        <h3 style="margin:0 0 12px;font-size:15px;word-break:break-word">${esc(item.name)}</h3>
        <dl class="details-dl">
          <dt>種類</dt><dd>${Number(item.is_folder) === 1 ? 'フォルダ' : esc(item.mime_type)}</dd>
          <dt>サイズ</dt><dd>${esc(formatBytes(item.size_bytes, Number(item.is_folder) === 1))}</dd>
          <dt>オーナー</dt><dd>${esc(item.owner_name)}</dd>
          <dt>更新日時</dt><dd>${esc(formatDate(item.updated_at))}</dd>
          <dt>共有</dt><dd>${Number(item.is_shared) === 1 ? 'リンクを知っている全員が閲覧可' : '非公開'}</dd>
        </dl>
        ${isTrashed ? '' : `<div class="form-field" style="margin-top:14px">
          <label for="detail-desc-input">説明</label>
          <textarea id="detail-desc-input" class="textarea-input" rows="3" placeholder="説明を追加">${esc(item.description || '')}</textarea>
          <button type="button" id="detail-desc-save" class="btn-secondary" style="width:fit-content;margin-top:4px">説明を保存</button>
        </div>`}
        <h3 style="margin:16px 0 8px;font-size:14px">アクティビティ</h3>
        <ul class="activity-list">
          ${activities.length ? activities.map((a) => `<li class="activity-item"><strong>${esc(a.actor_name)}</strong>: ${esc(a.detail)}<time>${esc(formatDate(a.created_at))}</time></li>`).join('') : '<li class="activity-item">履歴はまだありません</li>'}
        </ul>
      `;
      detailsContent.replaceChildren(wrap);
      const saveBtn = document.getElementById('detail-desc-save');
      if (saveBtn) {
        saveBtn.addEventListener('click', async () => {
          const val = document.getElementById('detail-desc-input').value;
          try {
            await apiRequest(`/api/items/${item.id}`, {
              method: 'PATCH',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ description: val })
            });
            showToast('説明を保存しました');
            await refreshItems();
            await openDetails(item.id);
          } catch (e) {
            showToast(e.message);
          }
        });
      }
    } catch (e) {
      showToast(e.message);
    }
  }

  function openTrashedPrompt(item) {
    const p = document.createElement('p');
    p.textContent = `「${item.name}」はゴミ箱にあります。開くには先に復元してください。`;
    openModal('ゴミ箱のアイテム', p, [
      { label: '閉じる', className: 'btn-secondary', onClick: closeModal },
      { label: '復元する', className: 'btn-primary', onClick: async () => { closeModal(); await restoreItem(item); } }
    ]);
  }

  async function openPreview(item) {
    if (!previewBackdrop) return;
    if (Number(item.is_trashed) === 1) {
      openTrashedPrompt(item);
      return;
    }
    if (Number(item.is_folder) === 1) {
      window.location.href = `/drive/folders/${item.id}`;
      return;
    }
    previewTitle.textContent = item.name;
    previewDownloadLink.href = `/api/files/${item.id}/download`;
    previewBody.innerHTML = '<p style="color:#ffffff">読み込み中...</p>';
    previewBackdrop.classList.add('open');

    const mime = (item.mime_type || '').toLowerCase();
    const previewUrl = `/api/files/${item.id}/preview`;

    if (INLINE_IMAGE_MIMES.has(mime)) {
      const img = document.createElement('img');
      img.className = 'preview-img';
      img.src = previewUrl;
      img.alt = item.name;
      previewBody.replaceChildren(img);
      return;
    }
    if (mime === 'application/pdf') {
      const iframe = document.createElement('iframe');
      iframe.className = 'preview-pdf';
      iframe.src = previewUrl;
      iframe.title = item.name;
      previewBody.replaceChildren(iframe);
      return;
    }

    try {
      const data = await apiRequest(previewUrl);
      const pre = document.createElement('pre');
      pre.className = 'preview-doc';
      pre.textContent = data.content ?? 'プレビューを利用できません。ダウンロードしてご確認ください。';
      previewBody.replaceChildren(pre);
    } catch (_) {
      const box = document.createElement('div');
      box.className = 'preview-doc';
      box.textContent = `${item.name} のインラインプレビューは非対応です。右上のボタンからダウンロードしてください。`;
      previewBody.replaceChildren(box);
    }
  }

  function closePreview() {
    if (previewBackdrop) previewBackdrop.classList.remove('open');
    if (previewBody) previewBody.replaceChildren();
  }

  function openNewFolderModal() {
    closeMenus();
    const wrap = document.createElement('div');
    wrap.className = 'form-field';
    wrap.innerHTML = '<label for="new-folder-name">フォルダ名</label><input id="new-folder-name" class="text-input" type="text" value="無題のフォルダ" maxlength="200" required>';
    const submit = async () => {
      const name = document.getElementById('new-folder-name').value.trim();
      if (!name) return;
      try {
        await apiRequest('/api/folders', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ name, parent_id: state.folderId })
        });
        closeModal();
        showToast(`フォルダ「${name}」を作成しました`);
        await refreshItems();
      } catch (e) {
        showToast(e.message);
      }
    };
    openModal('新しいフォルダ', wrap, [
      { label: 'キャンセル', className: 'btn-secondary', onClick: closeModal },
      { label: '作成', className: 'btn-primary', onClick: submit }
    ], submit);
  }

  function openRenameModal(item) {
    closeMenus();
    const wrap = document.createElement('div');
    wrap.className = 'form-field';
    wrap.innerHTML = `<label for="rename-input">新しい名前</label><input id="rename-input" class="text-input" type="text" value="${esc(item.name)}" maxlength="200" required>`;
    const submit = async () => {
      const name = document.getElementById('rename-input').value.trim();
      if (!name) return;
      try {
        await apiRequest(`/api/items/${item.id}`, {
          method: 'PATCH',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ name })
        });
        closeModal();
        showToast('名前を変更しました');
        await refreshItems();
      } catch (e) {
        showToast(e.message);
      }
    };
    openModal('名前を変更', wrap, [
      { label: 'キャンセル', className: 'btn-secondary', onClick: closeModal },
      { label: 'OK', className: 'btn-primary', onClick: submit }
    ], submit);
  }

  async function openMoveModal(item) {
    closeMenus();
    try {
      const data = await apiRequest('/api/items?view=folders');
      const folders = (data.items || []).filter((f) => Number(f.id) !== Number(item.id));
      const wrap = document.createElement('div');
      wrap.className = 'form-field';
      wrap.innerHTML = `<p style="margin:0 0 8px">「${esc(item.name)}」の移動先フォルダを選択してください</p>
        <div class="move-folder-list" role="radiogroup" aria-label="移動先フォルダ">
          <label class="move-folder-option">
            <input type="radio" name="move-target" value="" ${item.parent_id === null ? 'checked' : ''}>
            <span>マイドライブ（ルート）</span>
          </label>
          ${folders.map((f) => `<label class="move-folder-option">
            <input type="radio" name="move-target" value="${f.id}" ${Number(item.parent_id) === Number(f.id) ? 'checked' : ''}>
            <span>${esc(f.name)}</span>
          </label>`).join('')}
        </div>`;
      const submit = async () => {
        const checked = wrap.querySelector('input[name="move-target"]:checked');
        const val = checked ? checked.value : '';
        const targetId = val === '' ? null : Number(val);
        try {
          await apiRequest(`/api/items/${item.id}`, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ parent_id: targetId })
          });
          closeModal();
          showToast('アイテムを移動しました');
          await refreshItems();
        } catch (e) {
          showToast(e.message);
        }
      };
      openModal('アイテムを移動', wrap, [
        { label: 'キャンセル', className: 'btn-secondary', onClick: closeModal },
        { label: 'ここに移動', className: 'btn-primary', onClick: submit }
      ], submit);
    } catch (e) {
      showToast(e.message);
    }
  }

  function openShareModal(item) {
    closeMenus();
    const wrap = document.createElement('div');
    wrap.style.display = 'flex';
    wrap.style.flexDirection = 'column';
    wrap.style.gap = '12px';
    const isShared = Number(item.is_shared) === 1;
    const shareUrl = item.share_token ? `${window.location.origin}/share/${item.share_token}` : '';
    wrap.innerHTML = `
      <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
        <input id="share-toggle" type="checkbox" ${isShared ? 'checked' : ''}>
        <span>リンクを知っている全員に公開する</span>
      </label>
      <div class="form-field" id="share-url-wrap" style="${isShared ? '' : 'display:none'}">
        <label for="share-url-input">共有リンク</label>
        <input id="share-url-input" class="text-input" type="text" readonly value="${esc(shareUrl)}">
      </div>
    `;

    openModal(`「${item.name}」を共有`, wrap, [
      {
        id: 'share-copy-btn',
        label: 'リンクをコピー',
        className: 'btn-secondary',
        disabled: !isShared || !shareUrl,
        onClick: async () => {
          const input = document.getElementById('share-url-input');
          if (!input || !input.value) return;
          try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
              await navigator.clipboard.writeText(input.value);
            } else {
              input.select();
              document.execCommand('copy');
            }
            showToast('共有リンクをコピーしました');
          } catch (_) {
            showToast('クリップボードへのコピーに失敗しました');
          }
        }
      },
      { label: '完了', className: 'btn-primary', onClick: closeModal }
    ]);

    const toggle = document.getElementById('share-toggle');
    toggle.addEventListener('change', async () => {
      const nextChecked = toggle.checked;
      try {
        const res = await apiRequest(`/api/items/${item.id}`, {
          method: 'PATCH',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ is_shared: nextChecked })
        });
        const updated = res.item;
        const urlWrap = document.getElementById('share-url-wrap');
        const urlInput = document.getElementById('share-url-input');
        const copyBtn = document.getElementById('share-copy-btn');
        if (updated.is_shared && updated.share_token) {
          urlWrap.style.display = '';
          urlInput.value = `${window.location.origin}/share/${updated.share_token}`;
          if (copyBtn) copyBtn.disabled = false;
        } else {
          urlWrap.style.display = 'none';
          urlInput.value = '';
          if (copyBtn) copyBtn.disabled = true;
        }
        showToast(nextChecked ? '共有リンクを有効にしました' : '共有を停止しました（旧リンクは無効化されました）');
        await refreshItems();
      } catch (e) {
        toggle.checked = !nextChecked;
        showToast(e.message);
      }
    });
  }

  async function toggleStar(item) {
    closeMenus();
    try {
      const next = Number(item.is_starred) !== 1;
      await apiRequest(`/api/items/${item.id}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ is_starred: next })
      });
      showToast(next ? 'スター付きに追加しました' : 'スター付きから削除しました');
      await refreshItems();
    } catch (e) {
      showToast(e.message);
    }
  }

  async function deleteItem(item, permanent = false) {
    closeMenus();
    try {
      await apiRequest(`/api/items/${item.id}${permanent ? '?permanent=1' : ''}`, { method: 'DELETE' });
      showToast(permanent ? '完全に削除しました' : 'ゴミ箱に移動しました');
      selectedId = null;
      await refreshItems();
    } catch (e) {
      showToast(e.message);
    }
  }

  async function restoreItem(item) {
    closeMenus();
    try {
      await apiRequest(`/api/items/${item.id}/restore`, { method: 'POST' });
      showToast(`「${item.name}」を復元しました`);
      selectedId = null;
      await refreshItems();
    } catch (e) {
      showToast(e.message);
    }
  }

  async function uploadFiles(fileList) {
    if (!fileList || fileList.length === 0) return;
    let uploadedCount = 0;
    for (const file of fileList) {
      const fd = new FormData();
      fd.append('file', file);
      if (state.folderId) fd.append('parent_id', String(state.folderId));
      try {
        await apiRequest('/api/files/upload', { method: 'POST', body: fd });
        uploadedCount++;
      } catch (e) {
        showToast(`${file.name}: ${e.message}`);
      }
    }
    if (uploadedCount > 0) {
      showToast(`${uploadedCount}件のファイルをアップロードしました`);
      await refreshItems();
    }
  }

  function showItemMenuAt(item, posX, posY) {
    if (!itemMenu) return;
    activeMenuItemId = item.id;
    const isTrashed = Number(item.is_trashed) === 1;
    const isFolder = Number(item.is_folder) === 1;
    itemMenu.innerHTML = isTrashed
      ? `<button type="button" class="menu-item" data-menu-action="restore">復元する</button>
         <button type="button" class="menu-item danger" data-menu-action="delete-permanent">完全に削除</button>`
      : `<button type="button" class="menu-item" data-menu-action="open">${isFolder ? 'フォルダを開く' : 'プレビュー'}</button>
         ${isFolder ? '' : `<a class="menu-item" href="/api/files/${item.id}/download">ダウンロード</a>`}
         <button type="button" class="menu-item" data-menu-action="share">共有リンク</button>
         <button type="button" class="menu-item" data-menu-action="star">${Number(item.is_starred) === 1 ? 'スターを外す' : 'スターを付ける'}</button>
         <button type="button" class="menu-item" data-menu-action="rename">名前を変更</button>
         <button type="button" class="menu-item" data-menu-action="move">移動</button>
         <button type="button" class="menu-item" data-menu-action="details">詳細とアクティビティ</button>
         <div class="menu-sep"></div>
         <button type="button" class="menu-item danger" data-menu-action="trash">ゴミ箱に移動</button>`;
    itemMenu.classList.add('open');

    const menuW = itemMenu.offsetWidth || 230;
    const menuH = itemMenu.offsetHeight || 290;
    const left = Math.min(window.innerWidth - menuW - 12, Math.max(12, posX));
    const top = Math.min(window.innerHeight - menuH - 12, Math.max(12, posY));
    itemMenu.style.left = left + 'px';
    itemMenu.style.top = top + 'px';
  }

  // Event bindings
  if (menuToggleBtn && sidebar && scrim) {
    menuToggleBtn.addEventListener('click', () => {
      const opening = !sidebar.classList.contains('open');
      sidebar.classList.toggle('open', opening);
      scrim.classList.toggle('open', opening);
      menuToggleBtn.setAttribute('aria-expanded', String(opening));
    });
    scrim.addEventListener('click', closeDrawer);
  }

  function openNewPopover(anchor) {
    if (!newMenu) return;
    const isOpen = newMenu.classList.contains('open');
    closeMenus();
    if (!isOpen) {
      const rect = anchor.getBoundingClientRect();
      newMenu.classList.add('open');
      const left = Math.min(window.innerWidth - 240, Math.max(12, rect.left));
      const top = Math.min(window.innerHeight - 160, rect.bottom + 6);
      newMenu.style.left = left + 'px';
      newMenu.style.top = top + 'px';
      if (newBtn) newBtn.setAttribute('aria-expanded', 'true');
    }
  }

  if (newBtn) newBtn.addEventListener('click', (e) => { e.stopPropagation(); openNewPopover(newBtn); });
  if (mobileFab) mobileFab.addEventListener('click', (e) => { e.stopPropagation(); openNewPopover(mobileFab); });

  const newFolderBtn = document.getElementById('menu-new-folder');
  const uploadTriggerBtn = document.getElementById('menu-upload-file');
  if (newFolderBtn) newFolderBtn.addEventListener('click', openNewFolderModal);
  if (uploadTriggerBtn && fileUploadInput) {
    uploadTriggerBtn.addEventListener('click', () => { closeMenus(); fileUploadInput.click(); });
    fileUploadInput.addEventListener('change', () => {
      uploadFiles(fileUploadInput.files);
      fileUploadInput.value = '';
    });
  }

  if (itemMenu) {
    itemMenu.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-menu-action]');
      if (!btn) return;
      const item = (state.items || []).find((i) => Number(i.id) === Number(activeMenuItemId));
      if (!item) return;
      const act = btn.getAttribute('data-menu-action');
      if (act === 'open') { closeMenus(); openPreview(item); }
      else if (act === 'share') openShareModal(item);
      else if (act === 'star') toggleStar(item);
      else if (act === 'rename') openRenameModal(item);
      else if (act === 'move') openMoveModal(item);
      else if (act === 'details') { closeMenus(); selectedId = item.id; syncSelectionVisuals(); openDetails(item.id); }
      else if (act === 'trash') deleteItem(item, false);
      else if (act === 'delete-permanent') deleteItem(item, true);
      else if (act === 'restore') restoreItem(item);
    });
  }

  document.addEventListener('click', (e) => {
    if (!e.target.closest('#new-menu') && !e.target.closest('#new-btn') && !e.target.closest('#mobile-fab') && !e.target.closest('#item-menu') && !e.target.closest('[data-action="menu"]')) {
      closeMenus();
    }
  });

  const handleFileAreaClick = (e) => {
    const menuBtn = e.target.closest('[data-action="menu"]');
    if (menuBtn) {
      e.preventDefault();
      e.stopPropagation();
      const id = Number(menuBtn.getAttribute('data-item-id'));
      const item = (state.items || []).find((i) => Number(i.id) === id);
      if (item) {
        const rect = menuBtn.getBoundingClientRect();
        selectedId = id;
        syncSelectionVisuals();
        showItemMenuAt(item, rect.right - 230, rect.bottom + 4);
      }
      return;
    }

    const openLink = e.target.closest('[data-action="open"]');
    if (openLink) {
      const id = Number(openLink.getAttribute('data-item-id'));
      const item = (state.items || []).find((i) => Number(i.id) === id);
      if (item) {
        if (Number(item.is_trashed) === 1 || Number(item.is_folder) === 0) {
          e.preventDefault();
          selectedId = id;
          syncSelectionVisuals();
          openPreview(item);
        }
      }
      return;
    }

    const row = e.target.closest('[data-item-id]');
    if (row) {
      selectedId = Number(row.getAttribute('data-item-id'));
      syncSelectionVisuals();
      if (detailsPanel && detailsPanel.classList.contains('open')) {
        openDetails(selectedId);
      }
    }
  };

  const handleContextMenu = (e) => {
    const row = e.target.closest('[data-item-id]');
    if (!row) return;
    e.preventDefault();
    const id = Number(row.getAttribute('data-item-id'));
    const item = (state.items || []).find((i) => Number(i.id) === id);
    if (item) {
      selectedId = id;
      syncSelectionVisuals();
      showItemMenuAt(item, e.clientX, e.clientY);
    }
  };

  if (filesListWrap) {
    filesListWrap.addEventListener('click', handleFileAreaClick);
    filesListWrap.addEventListener('contextmenu', handleContextMenu);
  }
  if (filesGridWrap) {
    filesGridWrap.addEventListener('click', handleFileAreaClick);
    filesGridWrap.addEventListener('contextmenu', handleContextMenu);
  }

  // Selection toolbar buttons
  const selDownloadBtn = document.getElementById('sel-download-btn');
  const selStarBtn = document.getElementById('sel-star-btn');
  const selShareBtn = document.getElementById('sel-share-btn');
  const selRenameBtn = document.getElementById('sel-rename-btn');
  const selMoveBtn = document.getElementById('sel-move-btn');
  const selTrashBtn = document.getElementById('sel-trash-btn');
  const selRestoreBtn = document.getElementById('sel-restore-btn');
  const selClearBtn = document.getElementById('sel-clear-btn');

  if (selDownloadBtn) selDownloadBtn.addEventListener('click', () => { const it = getSelectedItem(); if (it) window.location.href = `/api/files/${it.id}/download`; });
  if (selStarBtn) selStarBtn.addEventListener('click', () => { const it = getSelectedItem(); if (it) toggleStar(it); });
  if (selShareBtn) selShareBtn.addEventListener('click', () => { const it = getSelectedItem(); if (it) openShareModal(it); });
  if (selRenameBtn) selRenameBtn.addEventListener('click', () => { const it = getSelectedItem(); if (it) openRenameModal(it); });
  if (selMoveBtn) selMoveBtn.addEventListener('click', () => { const it = getSelectedItem(); if (it) openMoveModal(it); });
  if (selTrashBtn) selTrashBtn.addEventListener('click', () => { const it = getSelectedItem(); if (it) deleteItem(it, Number(it.is_trashed) === 1); });
  if (selRestoreBtn) selRestoreBtn.addEventListener('click', () => { const it = getSelectedItem(); if (it) restoreItem(it); });
  if (selClearBtn) selClearBtn.addEventListener('click', () => { selectedId = null; syncSelectionVisuals(); });

  const emptyTrashBtn = document.getElementById('empty-trash-btn');
  if (emptyTrashBtn) {
    emptyTrashBtn.addEventListener('click', () => {
      const p = document.createElement('p');
      p.textContent = 'ゴミ箱内のすべてのアイテムが完全に削除されます。この操作は取り消せません。';
      openModal('ゴミ箱を空にしますか？', p, [
        { label: 'キャンセル', className: 'btn-secondary', onClick: closeModal },
        {
          label: '完全に削除',
          className: 'btn-danger',
          onClick: async () => {
            try {
              await apiRequest('/api/trash/empty', { method: 'POST' });
              closeModal();
              showToast('ゴミ箱を空にしました');
              await refreshItems();
            } catch (e) {
              showToast(e.message);
            }
          }
        }
      ]);
    });
  }

  // View Mode Toggle
  if (viewListBtn) viewListBtn.addEventListener('click', () => { viewMode = 'list'; try { localStorage.setItem('clouddrive_view_mode', 'list'); } catch (_) {} renderItems(); });
  if (viewGridBtn) viewGridBtn.addEventListener('click', () => { viewMode = 'grid'; try { localStorage.setItem('clouddrive_view_mode', 'grid'); } catch (_) {} renderItems(); });

  // Details Panel Toggle
  if (detailsToggleBtn && detailsPanel) {
    detailsToggleBtn.addEventListener('click', () => {
      const opening = !detailsPanel.classList.contains('open');
      if (opening) openDetails(selectedId);
      else {
        detailsPanel.classList.remove('open');
        detailsToggleBtn.setAttribute('aria-pressed', 'false');
      }
    });
  }
  if (detailsCloseBtn && detailsPanel) {
    detailsCloseBtn.addEventListener('click', () => {
      detailsPanel.classList.remove('open');
      if (detailsToggleBtn) detailsToggleBtn.setAttribute('aria-pressed', 'false');
    });
  }

  // Filter chips & Sort
  document.querySelectorAll('[data-filter-type]').forEach((btn) => {
    btn.addEventListener('click', () => {
      state.type = btn.getAttribute('data-filter-type') || 'all';
      document.querySelectorAll('[data-filter-type]').forEach((b) => b.setAttribute('aria-pressed', String(b === btn)));
      refreshItems();
    });
  });

  if (sortSelect) {
    sortSelect.addEventListener('change', () => {
      state.sort = sortSelect.value;
      refreshItems();
    });
  }
  if (sortDirBtn) {
    sortDirBtn.addEventListener('click', () => {
      state.dir = state.dir === 'asc' ? 'desc' : 'asc';
      sortDirBtn.textContent = state.dir === 'asc' ? '昇順 ↑' : '降順 ↓';
      refreshItems();
    });
  }

  // Search Input
  if (searchInput && searchClearBtn) {
    const syncClear = () => searchClearBtn.classList.toggle('visible', searchInput.value.length > 0);
    syncClear();
    searchInput.addEventListener('input', () => {
      syncClear();
      if (!filesTbody) return;
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => {
        state.q = searchInput.value.trim();
        refreshItems();
      }, 220);
    });
    searchClearBtn.addEventListener('click', () => {
      searchInput.value = '';
      state.q = '';
      syncClear();
      searchInput.focus();
      if (filesTbody) refreshItems();
    });
  }

  // Drag & Drop Upload (fixed #main-content ID!)
  if (workspaceMain && dropOverlay) {
    let dragDepth = 0;
    workspaceMain.addEventListener('dragenter', (e) => {
      if (e.dataTransfer && Array.from(e.dataTransfer.types).includes('Files')) {
        e.preventDefault();
        dragDepth++;
        dropOverlay.classList.add('active');
      }
    });
    workspaceMain.addEventListener('dragover', (e) => { e.preventDefault(); });
    workspaceMain.addEventListener('dragleave', () => {
      dragDepth = Math.max(0, dragDepth - 1);
      if (dragDepth === 0) dropOverlay.classList.remove('active');
    });
    workspaceMain.addEventListener('drop', (e) => {
      e.preventDefault();
      dragDepth = 0;
      dropOverlay.classList.remove('active');
      if (e.dataTransfer && e.dataTransfer.files.length > 0) {
        uploadFiles(e.dataTransfer.files);
      }
    });
  }

  // Modal & Preview close
  if (modalBackdrop) modalBackdrop.addEventListener('click', (e) => { if (e.target === modalBackdrop) closeModal(); });
  if (previewCloseBtn) previewCloseBtn.addEventListener('click', closePreview);
  if (previewBackdrop) previewBackdrop.addEventListener('click', (e) => { if (e.target === previewBackdrop) closePreview(); });

  // Full Google Drive Keyboard Shortcuts
  document.addEventListener('keydown', (e) => {
    const inField = document.activeElement && ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName);
    if (e.key === 'Escape') {
      closeMenus();
      closeModal();
      closePreview();
      closeDrawer();
      return;
    }
    if (inField) return;

    if (e.key === '/' && searchInput) {
      e.preventDefault();
      searchInput.focus();
    } else if (e.key === 'i' && detailsToggleBtn) {
      detailsToggleBtn.click();
    } else if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && state.items && state.items.length > 0) {
      e.preventDefault();
      const idx = state.items.findIndex((x) => Number(x.id) === Number(selectedId));
      const nextIdx = e.key === 'ArrowDown'
        ? (idx < state.items.length - 1 ? idx + 1 : 0)
        : (idx > 0 ? idx - 1 : state.items.length - 1);
      selectedId = state.items[nextIdx].id;
      syncSelectionVisuals();
    } else if (e.key === 'Enter') {
      const it = getSelectedItem();
      if (it) openPreview(it);
    } else if (e.key === 'F2') {
      const it = getSelectedItem();
      if (it && Number(it.is_trashed) === 0) openRenameModal(it);
    } else if (e.key === 'Delete' || e.key === 'Backspace') {
      const it = getSelectedItem();
      if (it) deleteItem(it, Number(it.is_trashed) === 1);
    }
  });

  if (viewMode === 'grid' && filesTbody) {
    renderItems();
  }
}
