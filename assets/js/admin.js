/**
 * Marche Management Plugin 管理画面JavaScript
 *
 * @package MarcheManagement
 * @author AI Assistant
 * @version 1.0.0
 */

(() => {
    'use strict';

    /**
     * 管理画面の初期化
     */
    document.addEventListener('DOMContentLoaded', () => {
        // 削除確認ダイアログ
        initDeleteConfirmation();

        // フォームバリデーション
        initFormValidation();

        // 通知メッセージの自動非表示
        initNoticeAutoHide();

        // テーブルのソート機能
        initTableSort();

        // ドラッグ&ドロップソート機能
        initDragDropSort();

        // 数値入力フィールドの初期化
        initNumberFields();

        // レンタル用品管理画面の初期化
        if (document.querySelector('.marche-rental-list, .marche-rental-form')) {
            initRentalManagement();
        }
    });

    /**
     * 削除確認ダイアログの初期化
     */
    const initDeleteConfirmation = () => {
        document.querySelectorAll('.marche-delete-confirm').forEach(button => {
            button.addEventListener('click', (e) => {
                const message = button.dataset.confirmMessage || 'この項目を削除しますか？';
                if (!confirm(message)) {
                    e.preventDefault();
                    return false;
                }
            });
        });
    };

    /**
     * フォームバリデーションの初期化
     */
    const initFormValidation = () => {
        // リアルタイムバリデーション
        document.querySelectorAll('form.marche-form').forEach(form => {
            form.addEventListener('submit', (e) => {
                let isValid = true;

                // 必須フィールドのチェック
                form.querySelectorAll('[required]').forEach(field => {
                    const value = field.value.trim();

                    if (!value) {
                        showFieldError(field, '必須項目です。');
                        isValid = false;
                    } else {
                        hideFieldError(field);
                    }
                });

                // メールアドレスの形式チェック
                form.querySelectorAll('input[type="email"]').forEach(field => {
                    const value = field.value.trim();

                    if (value && !isValidEmail(value)) {
                        showFieldError(field, 'メールアドレスの形式が正しくありません。');
                        isValid = false;
                    }
                });

                // 数値フィールドのチェック
                form.querySelectorAll('input[type="number"]').forEach(field => {
                    const value = field.value.trim();
                    const min = field.getAttribute('min');
                    const max = field.getAttribute('max');

                    if (value) {
                        const numValue = parseFloat(value);

                        if (isNaN(numValue)) {
                            showFieldError(field, '数値を入力してください。');
                            isValid = false;
                        } else {
                            if (min !== null && numValue < parseFloat(min)) {
                                showFieldError(field, `${min}以上の値を入力してください。`);
                                isValid = false;
                            }
                            if (max !== null && numValue > parseFloat(max)) {
                                showFieldError(field, `${max}以下の値を入力してください。`);
                                isValid = false;
                            }
                        }
                    }
                });

                if (!isValid) {
                    e.preventDefault();
                    return false;
                }
            });
        });

        // リアルタイムバリデーション（入力中）
        document.querySelectorAll('form.marche-form [required]').forEach(field => {
            field.addEventListener('blur', () => {
                const value = field.value.trim();

                if (!value) {
                    showFieldError(field, '必須項目です。');
                } else {
                    hideFieldError(field);
                }
            });
        });
    };

    /**
     * フィールドエラーメッセージの表示
     */
    const showFieldError = (field, message) => {
        hideFieldError(field);
        field.classList.add('error');

        const errorDiv = document.createElement('div');
        errorDiv.className = 'marche-error-message';
        errorDiv.textContent = message;
        field.insertAdjacentElement('afterend', errorDiv);
    };

    /**
     * フィールドエラーメッセージの非表示
     */
    const hideFieldError = (field) => {
        field.classList.remove('error');
        const errorMessage = field.parentNode.querySelector('.marche-error-message');
        if (errorMessage) {
            errorMessage.remove();
        }
    };

    /**
     * メールアドレスの形式チェック
     */
    const isValidEmail = (email) => {
        const regex = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
        return regex.test(email);
    };

    /**
     * 通知メッセージの自動非表示
     */
    const initNoticeAutoHide = () => {
        document.querySelectorAll('.notice.is-dismissible').forEach(notice => {
            setTimeout(() => {
                notice.style.opacity = '0';
                setTimeout(() => notice.remove(), 500);
            }, 5000);
        });
    };

    /**
     * テーブルのソート機能の初期化
     */
    const initTableSort = () => {
        document.querySelectorAll('.marche-sortable-table th.sortable').forEach(th => {
            th.addEventListener('click', () => {
                const table = th.closest('table');
                const columnIndex = Array.from(th.parentNode.children).indexOf(th);
                const isAsc = th.classList.contains('asc');

                // ソート状態のリセット
                table.querySelectorAll('th').forEach(header => {
                    header.classList.remove('asc', 'desc');
                });

                // 新しいソート状態の設定
                if (isAsc) {
                    th.classList.add('desc');
                    sortTable(table, columnIndex, false);
                } else {
                    th.classList.add('asc');
                    sortTable(table, columnIndex, true);
                }
            });
        });
    };

    /**
     * テーブルのソート処理
     */
    const sortTable = (table, columnIndex, isAsc) => {
        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));

        rows.sort((a, b) => {
            const aText = a.querySelectorAll('td')[columnIndex]?.textContent.trim() || '';
            const bText = b.querySelectorAll('td')[columnIndex]?.textContent.trim() || '';

            // 数値の場合は数値として比較
            const aNum = parseFloat(aText);
            const bNum = parseFloat(bText);

            if (!isNaN(aNum) && !isNaN(bNum)) {
                return isAsc ? aNum - bNum : bNum - aNum;
            }

            // 文字列として比較
            return isAsc ? aText.localeCompare(bText) : bText.localeCompare(aText);
        });

        // ソートされた行を再挿入
        rows.forEach(row => tbody.appendChild(row));
    };

    /**
     * Ajax リクエスト処理
     */
    const ajaxRequest = async (action, data, callback) => {
        try {
            const response = await fetch(ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action,
                    ...data
                })
            });

            const result = await response.json();
            if (callback) callback(result);
        } catch (error) {
            console.error('Ajax request failed:', error);
            if (callback) callback({ success: false, data: { message: 'リクエストに失敗しました。' } });
        }
    };

    /**
     * ローディング表示
     */
    const showLoading = (element) => {
        element.classList.add('loading');
        element.disabled = true;
    };

    /**
     * ローディング非表示
     */
    const hideLoading = (element) => {
        element.classList.remove('loading');
        element.disabled = false;
    };

    /**
     * 成功メッセージの表示
     */
    const showSuccessMessage = (message) => {
        const notice = document.createElement('div');
        notice.className = 'notice notice-success is-dismissible';
        notice.innerHTML = `<p>${message}</p>`;

        const wrap = document.querySelector('.wrap');
        if (wrap) {
            wrap.insertBefore(notice, wrap.firstChild);
            setTimeout(() => notice.remove(), 5000);
        }
    };

    /**
     * エラーメッセージの表示
     */
    const showErrorMessage = (message) => {
        const notice = document.createElement('div');
        notice.className = 'notice notice-error is-dismissible';
        notice.innerHTML = `<p>${message}</p>`;

        const wrap = document.querySelector('.wrap');
        if (wrap) {
            wrap.insertBefore(notice, wrap.firstChild);
            setTimeout(() => notice.remove(), 5000);
        }
    };

    /**
     * 確認ダイアログの表示
     */
    const showConfirmDialog = (message, callback) => {
        if (confirm(message)) {
            callback();
        }
    };

    /**
     * HTML5 Drag and Drop APIを使用したドラッグ&ドロップソート機能の初期化
     */
    const initDragDropSort = () => {
        // ドラッグ対象の要素を取得
        const sortableContainers = document.querySelectorAll('#sortable-dates, #sortable-areas, #sortable-rentals');

        sortableContainers.forEach((container, containerIndex) => {
            if (!container) return;

            const rows = container.querySelectorAll('tr');
            let draggedElement = null;
            let draggedIndex = null;

            rows.forEach((row, index) => {
                try {
                    // ドラッグ可能に設定
                    row.draggable = true;
                    row.dataset.index = index;

                    // ドラッグハンドルの設定
                    const handle = row.querySelector('.sort-handle');
                    if (handle) {
                        handle.style.cursor = 'grab';

                        // ドラッグハンドルのマウスイベント
                        handle.addEventListener('mousedown', () => {
                            handle.style.cursor = 'grabbing';
                        });

                        handle.addEventListener('mouseup', () => {
                            handle.style.cursor = 'grab';
                        });
                    }

                    // ドラッグイベントリスナー
                    row.addEventListener('dragstart', (e) => {
                        draggedElement = row;
                        draggedIndex = index;
                        row.classList.add('dragging');
                        e.dataTransfer.effectAllowed = 'move';
                        e.dataTransfer.setData('text/html', row.outerHTML);
                    });

                    row.addEventListener('dragend', () => {
                        row.classList.remove('dragging');
                        if (handle) {
                            handle.style.cursor = 'grab';
                        }

                        // すべてのドラッグオーバー状態をクリア
                        container.querySelectorAll('tr').forEach(r => {
                            r.classList.remove('drag-over');
                        });
                    });

                    row.addEventListener('dragover', (e) => {
                        e.preventDefault();
                        e.dataTransfer.dropEffect = 'move';

                        if (draggedElement !== row) {
                            row.classList.add('drag-over');
                        }
                    });

                    row.addEventListener('dragleave', () => {
                        row.classList.remove('drag-over');
                    });

                    row.addEventListener('drop', (e) => {
                        e.preventDefault();

                        if (draggedElement && draggedElement !== row) {
                            const targetIndex = parseInt(row.dataset.index);

                            // 要素の移動
                            if (draggedIndex < targetIndex) {
                                row.parentNode.insertBefore(draggedElement, row.nextSibling);
                            } else {
                                row.parentNode.insertBefore(draggedElement, row);
                            }

                            // インデックスの再設定
                            updateRowIndices(container);

                            // 並び順変更の通知
                            showSortChangeNotice();
                        }

                        row.classList.remove('drag-over');
                    });
                } catch (error) {
                    console.error('ドラッグ機能の初期化エラー:', error);
                }
            });
        });
    };

    /**
     * 行のインデックスを更新
     */
    const updateRowIndices = (container) => {
        const rows = container.querySelectorAll('tr');
        rows.forEach((row, index) => {
            row.dataset.index = index;
        });
    };

    /**
     * 並び順変更の通知を表示
     */
    const showSortChangeNotice = () => {
        if (!document.querySelector('.sort-changed-notice')) {
            const sortForm = document.querySelector('#sort-form, .marche-list-actions');
            if (sortForm) {
                const notice = document.createElement('div');
                notice.className = 'notice notice-warning sort-changed-notice';
                notice.innerHTML = '<p>並び順が変更されました。「並び順を保存」ボタンをクリックして保存してください。</p>';
                sortForm.insertBefore(notice, sortForm.firstChild);
            }
        }
    };

    /**
     * 日付入力フィールドの初期化
     */
    const initDateFields = () => {
        // 今日以降の日付のみ選択可能にする
        const today = new Date().toISOString().split('T')[0];
        document.querySelectorAll('input[type="date"]').forEach(input => {
            input.setAttribute('min', today);

            // 日付変更時のバリデーション
            input.addEventListener('change', () => {
                const selectedDate = new Date(input.value);
                const todayDate = new Date();
                todayDate.setHours(0, 0, 0, 0);

                if (selectedDate < todayDate) {
                    showFieldError(input, '過去の日付は選択できません。');
                    input.value = '';
                } else {
                    hideFieldError(input);
                }
            });
        });
    };

    /**
     * 一括操作の初期化
     */
    const initBulkActions = () => {
        // 全選択/全解除
        const selectAll = document.querySelector('#select-all');
        if (selectAll) {
            selectAll.addEventListener('change', () => {
                const checked = selectAll.checked;
                document.querySelectorAll('.bulk-select').forEach(checkbox => {
                    checkbox.checked = checked;
                });
                updateBulkActionButtons();
            });
        }

        // 個別選択
        document.querySelectorAll('.bulk-select').forEach(checkbox => {
            checkbox.addEventListener('change', () => {
                updateBulkActionButtons();

                // 全選択チェックボックスの状態更新
                const totalItems = document.querySelectorAll('.bulk-select').length;
                const checkedItems = document.querySelectorAll('.bulk-select:checked').length;
                if (selectAll) {
                    selectAll.checked = totalItems === checkedItems;
                }
            });
        });

        // 一括操作ボタン
        document.querySelectorAll('.bulk-action-btn').forEach(button => {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                const action = button.dataset.action;
                const selectedIds = Array.from(document.querySelectorAll('.bulk-select:checked'))
                    .map(checkbox => checkbox.value);

                if (selectedIds.length === 0) {
                    alert('項目を選択してください。');
                    return;
                }

                const confirmMessage = getBulkActionConfirmMessage(action, selectedIds.length);
                if (confirm(confirmMessage)) {
                    executeBulkAction(action, selectedIds);
                }
            });
        });
    };

    /**
     * 一括操作ボタンの状態更新
     */
    const updateBulkActionButtons = () => {
        const checkedCount = document.querySelectorAll('.bulk-select:checked').length;
        document.querySelectorAll('.bulk-action-btn').forEach(button => {
            button.disabled = checkedCount === 0;
        });
        document.querySelectorAll('.bulk-action-count').forEach(element => {
            element.textContent = checkedCount;
        });
    };

    /**
     * 一括操作の確認メッセージ取得
     */
    const getBulkActionConfirmMessage = (action, count) => {
        switch (action) {
            case 'delete':
                return `${count}件の項目を削除しますか？`;
            case 'activate':
                return `${count}件の項目を有効にしますか？`;
            case 'deactivate':
                return `${count}件の項目を無効にしますか？`;
            default:
                return `${count}件の項目に対して操作を実行しますか？`;
        }
    };

    /**
     * 一括操作の実行
     */
    const executeBulkAction = (action, selectedIds) => {
        const data = {
            action: 'marche_bulk_action',
            bulk_action: action,
            ids: selectedIds
        };

        ajaxRequest('marche_bulk_action', data, (response) => {
            if (response.success) {
                showSuccessMessage(response.data.message);
                location.reload();
            } else {
                showErrorMessage(response.data.message || 'エラーが発生しました。');
            }
        });
    };

    /**
     * 数値入力フィールドの初期化
     */
    const initNumberFields = () => {
        // 料金入力フィールドの処理
        document.querySelectorAll('input[name="price"]').forEach(input => {
            input.addEventListener('input', () => {
                const value = input.value;

                // 負の値を防ぐ
                if (value < 0) {
                    input.value = 0;
                    showFieldError(input, '料金は0以上の値を入力してください。');
                } else {
                    hideFieldError(input);
                }

                // 料金のリアルタイム表示更新
                updatePriceDisplay(input);
            });
        });

        // 定員入力フィールドの処理
        document.querySelectorAll('input[name="capacity"]').forEach(input => {
            input.addEventListener('input', () => {
                const value = input.value;

                // 負の値を防ぐ
                if (value < 0) {
                    input.value = 0;
                    showFieldError(input, '定員は0以上の値を入力してください。');
                } else {
                    hideFieldError(input);
                }

                // 定員のリアルタイム表示更新
                updateCapacityDisplay(input);
            });
        });

        // 並び順入力フィールドの処理
        document.querySelectorAll('input[name="sort_order"]').forEach(input => {
            input.addEventListener('input', () => {
                const value = input.value;

                if (value < 0) {
                    input.value = 0;
                    showFieldError(input, '並び順は0以上の値を入力してください。');
                } else {
                    hideFieldError(input);
                }
            });
        });

        // 数値フィールドの増減ボタン
        document.querySelectorAll('.number-input-group').forEach(group => {
            const input = group.querySelector('input[type="number"]');
            const incrementBtn = group.querySelector('.increment-btn');
            const decrementBtn = group.querySelector('.decrement-btn');

            if (incrementBtn) {
                incrementBtn.addEventListener('click', () => incrementValue(input));
            }

            if (decrementBtn) {
                decrementBtn.addEventListener('click', () => decrementValue(input));
            }
        });
    };

    /**
     * 料金表示の更新
     */
    const updatePriceDisplay = (input) => {
        // 価格プレビューは削除されたため、この関数は空にする
    };

    /**
     * 定員表示の更新
     */
    const updateCapacityDisplay = (input) => {
        const value = parseInt(input.value) || 0;
        const indicator = input.parentNode.querySelector('.capacity-indicator');

        if (indicator) {
            indicator.textContent = value === 0 ? '制限なし' : `${value}名`;
        }
    };

    /**
     * 数値の増加
     */
    const incrementValue = (input) => {
        const currentValue = parseInt(input.value) || 0;
        const max = parseInt(input.getAttribute('max')) || 999999;
        const step = parseInt(input.getAttribute('step')) || 1;

        if (currentValue < max) {
            input.value = currentValue + step;
            input.dispatchEvent(new Event('input'));
        }
    };

    /**
     * 数値の減少
     */
    const decrementValue = (input) => {
        const currentValue = parseInt(input.value) || 0;
        const min = parseInt(input.getAttribute('min')) || 0;
        const step = parseInt(input.getAttribute('step')) || 1;

        if (currentValue > min) {
            input.value = currentValue - step;
            input.dispatchEvent(new Event('input'));
        }
    };

    /**
     * 定員インジケーターの作成
     */
    const createCapacityIndicator = (used, total) => {
        const percentage = total > 0 ? (used / total) * 100 : 0;
        let className = 'capacity-low';

        if (percentage >= 80) {
            className = 'capacity-high';
        } else if (percentage >= 60) {
            className = 'capacity-medium';
        }

        return `<div class="capacity-indicator ${className}">
            <div class="capacity-bar" style="width: ${percentage}%"></div>
            <span class="capacity-text">${used}/${total}</span>
        </div>`;
    };

    /**
     * 合計料金の計算
     */
    const calculateTotalPrice = () => {
        let total = 0;

        // エリア料金の計算
        const areaSelect = document.querySelector('select[name="booth-location"]');
        if (areaSelect && areaSelect.value) {
            const areaPrice = parseFloat(areaSelect.dataset.price) || 0;
            total += areaPrice;
        }

        // レンタル用品料金の計算
        document.querySelectorAll('input[name^="rental-"]').forEach(input => {
            const quantity = parseInt(input.value) || 0;
            const price = parseFloat(input.dataset.price) || 0;
            total += quantity * price;
        });

        return total;
    };

    /**
     * 合計料金表示の更新
     */
    const updateTotalPriceDisplay = () => {
        const total = calculateTotalPrice();
        const formattedTotal = `¥${total.toLocaleString()}`;

        document.querySelectorAll('.total-price-display').forEach(element => {
            element.textContent = formattedTotal;
        });
    };

    /**
     * 検索フィルターの初期化
     */
    const initSearchFilter = () => {
        const searchInput = document.querySelector('.marche-search-input');
        if (searchInput) {
            let searchTimeout;

            searchInput.addEventListener('input', () => {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    const query = searchInput.value.toLowerCase();
                    filterTableRows(query);
                }, 300);
            });
        }
    };

    /**
     * テーブル行のフィルタリング
     */
    const filterTableRows = (query) => {
        document.querySelectorAll('.marche-table tbody tr').forEach(row => {
            const text = row.textContent.toLowerCase();
            const shouldShow = !query || text.includes(query);
            row.style.display = shouldShow ? '' : 'none';
        });
    };

    /**
     * レンタル用品管理の初期化
     */
    const initRentalManagement = () => {
        initRentalPriceFields();
        initRentalForm();
    };

    /**
     * レンタル用品料金フィールドの初期化
     */
    const initRentalPriceFields = () => {
        // 料金入力フィールドの処理
        document.querySelectorAll('.marche-price-field').forEach(input => {
            input.addEventListener('input', () => {
                const value = parseFloat(input.value) || 0;
                const formattedPrice = `¥${value.toLocaleString()}`;

                // 料金プレビューは削除されたため、この処理は不要

                // バリデーション
                if (value < 0) {
                    input.value = 0;
                    showFieldError(input, '料金は0以上の値を入力してください。');
                } else {
                    hideFieldError(input);
                }
            });

            // 料金フィールドのフォーカス時に全選択
            input.addEventListener('focus', () => {
                input.select();
            });

            // 料金フィールドのキーボード操作
            input.addEventListener('keydown', (e) => {
                // 矢印キーで値を増減
                if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    incrementPriceValue(input);
                } else if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    decrementPriceValue(input);
                }
            });
        });
    };

    /**
     * レンタル用品フォームの初期化
     */
    const initRentalForm = () => {
        // フォーム送信時のバリデーション
        document.querySelectorAll('.marche-rental-form form').forEach(form => {
            form.addEventListener('submit', (e) => {
                let isValid = true;
                const errorMessages = [];

                // レンタル用品名のバリデーション
                const itemName = form.querySelector('input[name="item_name"]');
                if (itemName && !itemName.value.trim()) {
                    errorMessages.push('レンタル用品名を入力してください。');
                    showFieldError(itemName, 'レンタル用品名を入力してください。');
                    isValid = false;
                }

                // 料金のバリデーション
                const price = form.querySelector('input[name="price"]');
                if (price) {
                    const priceValue = parseFloat(price.value) || 0;
                    if (priceValue < 0) {
                        errorMessages.push('料金は0以上の値を入力してください。');
                        showFieldError(price, '料金は0以上の値を入力してください。');
                        isValid = false;
                    }
                }

                // 単位のバリデーション
                const unit = form.querySelector('select[name="unit"]');
                if (unit && !unit.value) {
                    errorMessages.push('単位を選択してください。');
                    showFieldError(unit, '単位を選択してください。');
                    isValid = false;
                }

                if (!isValid) {
                    e.preventDefault();
                    showErrorMessage('入力内容にエラーがあります。確認してください。');
                    return false;
                }

                // 送信前にローディング表示
                const submitButton = form.querySelector('input[type="submit"]');
                if (submitButton) {
                    showLoading(submitButton);
                }
            });
        });

        // リアルタイムバリデーション
        document.querySelectorAll('input[name="item_name"]').forEach(input => {
            input.addEventListener('input', () => {
                const value = input.value.trim();
                if (value) {
                    hideFieldError(input);
                }
            });
        });

        document.querySelectorAll('select[name="unit"]').forEach(select => {
            select.addEventListener('change', () => {
                const value = select.value;
                if (value) {
                    hideFieldError(select);
                }
            });
        });
    };

    /**
     * 料金値を増加
     */
    const incrementPriceValue = (input) => {
        const currentValue = parseFloat(input.value) || 0;
        const step = 100; // 100円単位で増加
        const newValue = currentValue + step;

        input.value = newValue;
        input.dispatchEvent(new Event('input'));
    };

    /**
     * 料金値を減少
     */
    const decrementPriceValue = (input) => {
        const currentValue = parseFloat(input.value) || 0;
        const step = 100; // 100円単位で減少
        const min = 0;
        const newValue = currentValue - step;

        if (newValue >= min) {
            input.value = newValue;
            input.dispatchEvent(new Event('input'));
        }
    };

    /**
     * レンタル用品の料金計算
     */
    const calculateRentalPrice = (itemId, quantity) => {
        const unitPrice = parseFloat(document.querySelector(`input[data-rental-id="${itemId}"]`)?.dataset.price) || 0;
        return unitPrice * quantity;
    };

    /**
     * レンタル用品の料金表示更新
     */
    const updateRentalPriceDisplay = (itemId, quantity) => {
        const totalPrice = calculateRentalPrice(itemId, quantity);
        const formattedPrice = `¥${totalPrice.toLocaleString()}`;

        const priceDisplay = document.querySelector(`.rental-price-display[data-rental-id="${itemId}"]`);
        if (priceDisplay) {
            priceDisplay.textContent = formattedPrice;
        }
    };

    // 外部からアクセス可能な関数をグローバルに公開
    window.marcheAdmin = {
        showSuccessMessage,
        showErrorMessage,
        showConfirmDialog,
        ajaxRequest,
        showLoading,
        hideLoading,
        initDateFields,
        initBulkActions,
        initSearchFilter,
        initNumberFields,
        initRentalManagement
    };
})();
