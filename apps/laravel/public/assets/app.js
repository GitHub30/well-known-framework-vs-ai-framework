(() => {
  'use strict';

  let loaded = null;
  function loadDriveUi() {
    detach();
    if (!loaded) {
      loaded = import('/assets/drive-ui.js?v=2').then((m) => {
        m.initCloudDrive();
        return m;
      });
    }
    return loaded;
  }

  const hasDrive = Boolean(document.getElementById('files-tbody'));
  const hasShell = hasDrive || Boolean(document.getElementById('menu-toggle-btn'));

  // Hydrate immediately if user saved grid view (only on Drive workspace, scheduled on idle)
  if (hasDrive) {
    const checkGrid = () => {
      try {
        if (localStorage.getItem('clouddrive_view_mode') === 'grid') {
          loadDriveUi();
        }
      } catch (_) {}
    };
    if ('requestIdleCallback' in window) {
      window.requestIdleCallback(checkGrid, { timeout: 1500 });
    } else {
      setTimeout(checkGrid, 200);
    }
  }

  const onFirstClick = (e) => {
    const target = e.target && e.target.closest('button, a[data-action="open"], [data-item-id]');
    if (!target) return;
    if (target.tagName === 'A' && target.getAttribute('href') && !target.getAttribute('href').startsWith('#') && !target.hasAttribute('data-action')) {
      return;
    }
    if (target.tagName === 'A' && target.getAttribute('data-action') === 'open' && !target.getAttribute('href').startsWith('#') && target.getAttribute('data-is-trashed') !== '1') {
      return;
    }
    e.preventDefault();
    e.stopPropagation();
    loadDriveUi().then(() => target.click());
  };

  const onFirstContextMenu = (e) => {
    const row = e.target && e.target.closest('[data-item-id]');
    if (!row) return;
    e.preventDefault();
    const x = e.clientX;
    const y = e.clientY;
    loadDriveUi().then(() => {
      row.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, cancelable: true, clientX: x, clientY: y }));
    });
  };

  const onFirstInput = (e) => {
    if (!e.target || (e.target.id !== 'search-input' && e.target.id !== 'sort-select')) return;
    const t = e.target;
    const type = e.type;
    loadDriveUi().then(() => t.dispatchEvent(new Event(type, { bubbles: true })));
  };

  const onFirstKey = (e) => {
    if (['/', 'Escape', 'Enter', 'Delete', 'Backspace', 'F2', 'i', 'ArrowDown', 'ArrowUp'].includes(e.key)) {
      const key = e.key;
      loadDriveUi().then(() => document.dispatchEvent(new KeyboardEvent('keydown', { key })));
    }
  };

  const onFirstDrag = (e) => {
    if (e.dataTransfer && Array.from(e.dataTransfer.types).includes('Files')) {
      e.preventDefault();
      const overlay = document.getElementById('drop-overlay');
      if (overlay) overlay.classList.add('active');
      loadDriveUi();
    }
  };

  function detach() {
    document.removeEventListener('click', onFirstClick, true);
    document.removeEventListener('contextmenu', onFirstContextMenu, true);
    document.removeEventListener('input', onFirstInput, true);
    document.removeEventListener('change', onFirstInput, true);
    document.removeEventListener('keydown', onFirstKey, true);
    document.removeEventListener('dragenter', onFirstDrag, true);
  }

  if (hasShell) {
    document.addEventListener('click', onFirstClick, true);
    if (hasDrive) {
      document.addEventListener('contextmenu', onFirstContextMenu, true);
      document.addEventListener('input', onFirstInput, true);
      document.addEventListener('change', onFirstInput, true);
      document.addEventListener('keydown', onFirstKey, true);
      document.addEventListener('dragenter', onFirstDrag, true);
    }
  }

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      setTimeout(() => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {});
      }, 1500);
    });
  }
})();
