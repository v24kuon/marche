/**
 * Marche Management Plugin フロントエンドJavaScript
 *
 * @package MarcheManagement
 * @author AI Assistant
 * @version 1.0.0
 */

(() => {
    'use strict';

    /**
     * フロントエンドの初期化
     */
    document.addEventListener('DOMContentLoaded', () => {
        // Contact Form 7フォームの初期化
        initContactForm7();

        // フォームステップの表示/非表示制御の初期化
        initFormStepControl();
    });

    /**
     * フォームステップの表示/非表示制御の初期化
     */
    const initFormStepControl = () => {
        // 指定されたフォームステップ（form-1からform-7）の表示/非表示を切り替える
        const toggleFormSteps = (show) => {
            for (let i = 1; i <= 7; i++) {
                const formStep = document.getElementById(`form-${i}`);
                if (formStep) {
                    formStep.style.display = show ? '' : 'none';
                }
            }
        };

        // Contact Form 7のレスポンス出力メッセージを監視
        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                if (mutation.type === 'childList' || mutation.type === 'characterData') {
                    // レスポンス出力要素を取得
                    const responseOutput = document.querySelector('.wpcf7-response-output');
                    if (responseOutput) {
                        const outputText = responseOutput.textContent || responseOutput.innerText;

                        // 「支払いが必要です」メッセージが表示された場合のみフォームを非表示
                        if (outputText.includes('支払いが必要です')) {
                            toggleFormSteps(false);
                        }
                        // 「ありがとうございます」メッセージが表示された場合
                        else if (outputText.includes('お申し込みありがとうございます') && outputText.includes('決済は完了しました')) {
                            toggleFormSteps(false);
                        }
                    }
                }
            });
        });

        // 監視を開始
        observer.observe(document.body, {
            childList: true,
            subtree: true,
            characterData: true
        });
    };

    /**
     * Contact Form 7フォームの初期化
     */
    const initContactForm7 = () => {
        // 全てのContact Form 7フォームに対して処理
        document.querySelectorAll('.wpcf7-form').forEach(form => {
            const dateSelect = form.querySelector('select[name="date"]');
            const areaSelect = form.querySelector('select[name="booth-location"]');

            if (dateSelect && areaSelect) {
                // 開催日選択時のイベントリスナー
                dateSelect.addEventListener('change', (e) => {
                    updateAreaOptions(form, e.target.value);
                });

                // 初期状態でエリア選択肢を更新
                if (dateSelect.value) {
                    updateAreaOptions(form, dateSelect.value);
                } else {
                    // 開催日が選択されていない場合は、最初の開催日を自動選択
                    if (dateSelect.options.length > 1) {
                        dateSelect.selectedIndex = 1; // 最初の実際の選択肢を選択（0はプレースホルダー）
                        updateAreaOptions(form, dateSelect.value);
                    } else {
                        // 開催日の選択肢がない場合は適切なメッセージを表示
                        showAreaError(areaSelect, '開催日を選択してください');
                    }
                }
            }

            // 料金計算機能の初期化
            initPriceCalculation(form);
        });
    };

    /**
     * 料金計算機能の初期化
     *
     * @param {HTMLFormElement} form フォーム要素
     */
    const initPriceCalculation = async (form) => {
        // フォームIDの取得
        const formIdInput = form.querySelector('input[name="_wpcf7"]');
        if (!formIdInput) return;

        const formId = formIdInput.value;

        try {
            // 料金設定を取得
            const response = await fetch(marcheAjax.ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'get_form_pricing_settings',
                    form_id: formId,
                    nonce: marcheAjax.nonce
                })
            });

            const data = await response.json();

            if (data.success) {
                // 料金設定をフォームに保存
                form.dataset.pricingSettings = JSON.stringify(data.data);

                // 料金計算イベントリスナーを設定
                setupPriceCalculationListeners(form, data.data);

                // 初期料金を計算
                updatePriceDisplay(form, data.data);
            }
        } catch (error) {
            console.error('料金設定の取得に失敗しました:', error);
        }
    };

    /**
     * 料金計算イベントリスナーの設定
     *
     * @param {HTMLFormElement} form フォーム要素
     * @param {Object} pricingSettings 料金設定
     */
    const setupPriceCalculationListeners = (form, pricingSettings) => {
        // エリア選択の変更監視
        const areaSelect = form.querySelector('select[name="booth-location"]');
        if (areaSelect) {
            areaSelect.addEventListener('change', () => {
                updatePriceDisplay(form, pricingSettings);
            });
        }

        // レンタル用品数量の変更監視
        form.querySelectorAll('input[name^="rental-"]').forEach(input => {
            input.addEventListener('input', () => {
                updatePriceDisplay(form, pricingSettings);
            });
            input.addEventListener('change', () => {
                updatePriceDisplay(form, pricingSettings);
            });
        });
    };

    /**
     * 料金表示の更新
     *
     * @param {HTMLFormElement} form フォーム要素
     * @param {Object} pricingSettings 料金設定
     */
    const updatePriceDisplay = (form, pricingSettings) => {
        const totalPrice = calculateTotalPrice(form, pricingSettings);

        // Stripeボタンのテキストを更新
        const stripeButton = form.querySelector('button.second, button.wpcf7cf7-stripe-submit');
        if (stripeButton) {
            stripeButton.textContent = `${totalPrice.toLocaleString()}円を支払う`;
        }
    };

    /**
     * 合計料金の計算
     *
     * @param {HTMLFormElement} form フォーム要素
     * @param {Object} pricingSettings 料金設定
     * @return {number} 合計料金
     */
    const calculateTotalPrice = (form, pricingSettings) => {
        let totalPrice = 0;

        // エリア料金の計算
        const areaSelect = form.querySelector('select[name="booth-location"]');
        if (areaSelect && areaSelect.value && pricingSettings.areas) {
            const selectedArea = pricingSettings.areas.find(area =>
                area.name === areaSelect.value
            );
            if (selectedArea) {
                totalPrice += selectedArea.price;
            }
        }

        // レンタル用品料金の計算
        if (pricingSettings.rentals) {
            pricingSettings.rentals.forEach(rental => {
                const input = form.querySelector(`input[name="rental-${rental.field_name}"]`);
                if (input) {
                    const quantity = parseInt(input.value) || 0;
                    totalPrice += rental.price * quantity;
                }
            });
        }

        return Math.max(0, totalPrice);
    };

    /**
     * エリア選択肢の更新
     *
     * @param {HTMLFormElement} form フォーム要素
     * @param {string} selectedDate 選択された開催日
     */
    const updateAreaOptions = async (form, selectedDate) => {
        const areaSelect = form.querySelector('select[name="booth-location"]');
        if (!areaSelect) return;

        // 開催日が選択されていない場合は適切なメッセージを表示
        if (!selectedDate || selectedDate.trim() === '') {
            showAreaError(areaSelect, '開催日を選択してください');
            return;
        }

        // フォームIDの取得
        const formIdInput = form.querySelector('input[name="_wpcf7"]');
        if (!formIdInput) return;

        const formId = formIdInput.value;

        try {
            // ローディング状態を表示
            showAreaLoading(areaSelect);

            // AJAXリクエストでエリア選択肢を取得
            const response = await fetch(marcheAjax.ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'get_area_options_by_date',
                    form_id: formId,
                    date_label: selectedDate,
                    nonce: marcheAjax.nonce
                })
            });

            const data = await response.json();

            if (data.success) {
                // エリア選択肢を更新
                updateAreaSelectOptions(areaSelect, data.data);

                // 料金設定を再取得して料金を再計算
                const pricingSettings = JSON.parse(form.dataset.pricingSettings || '{}');
                if (pricingSettings.areas) {
                    updatePriceDisplay(form, pricingSettings);
                }
            } else {
                showAreaError(areaSelect, '開催日を選択してください');
            }

        } catch (error) {
            showAreaError(areaSelect, '通信エラーが発生しました');
        } finally {
            // ローディング状態を解除
            hideAreaLoading(areaSelect);
        }
    };

    /**
     * エリア選択肢の更新
     *
     * @param {HTMLSelectElement} select セレクト要素
     * @param {Object} options 選択肢データ
     */
    const updateAreaSelectOptions = (select, options) => {
        // 現在の選択値を保存
        const currentValue = select.value;

        // 既存の選択肢を削除
        select.innerHTML = '';

        // プレースホルダーオプションを追加
        const placeholderOption = document.createElement('option');
        placeholderOption.value = '';
        placeholderOption.textContent = 'エリアを選択してください';
        placeholderOption.selected = true;
        select.appendChild(placeholderOption);

        // 新しい選択肢を追加
        Object.entries(options).forEach(([value, label]) => {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = label;
            select.appendChild(option);
        });

        // 以前の選択値が新しい選択肢に存在する場合は復元
        if (currentValue && options[currentValue]) {
            select.value = currentValue;
        }

        // 選択肢が変更されたことを通知
        select.dispatchEvent(new Event('change'));
    };

    /**
     * エリア選択肢のローディング表示
     *
     * @param {HTMLSelectElement} select セレクト要素
     */
    const showAreaLoading = (select) => {
        select.disabled = true;
        select.classList.add('loading');

        // ローディング用のオプションを追加
        const loadingOption = document.createElement('option');
        loadingOption.value = '';
        loadingOption.textContent = '読み込み中...';
        loadingOption.selected = true;
        select.innerHTML = '';
        select.appendChild(loadingOption);
    };

    /**
     * エリア選択肢のローディング解除
     *
     * @param {HTMLSelectElement} select セレクト要素
     */
    const hideAreaLoading = (select) => {
        select.disabled = false;
        select.classList.remove('loading');
    };

    /**
     * エリア選択肢のエラー表示
     *
     * @param {HTMLSelectElement} select セレクト要素
     * @param {string} message エラーメッセージ
     */
    const showAreaError = (select, message) => {
        select.innerHTML = '';
        const errorOption = document.createElement('option');
        errorOption.value = '';
        errorOption.textContent = message;
        errorOption.selected = true;
        select.appendChild(errorOption);
    };

})();
