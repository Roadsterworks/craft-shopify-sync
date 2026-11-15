// Shopify Sync control panel behaviour, via delegated listeners and data attributes.
(() => {
    // Field mapping: show the namespace/key/type inputs when "Custom metafield…" is chosen.
    document.addEventListener('change', (event) => {
        const select = event.target.closest('[data-mapping-target]');
        if (!select) {
            return;
        }

        select
            .closest('[data-mapping-row]')
            .querySelector('[data-custom-metafield]')
            .classList.toggle('hidden', select.value !== select.dataset.customValue);
    });

    // “Sync now” and “Retry” buttons: queue a sync for the entry without leaving the page.
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-shopify-sync]');
        if (!button || button.disabled) {
            return;
        }

        button.classList.add('loading');
        button.disabled = true;

        Craft.sendActionRequest('POST', 'craft-shopify-sync/sync/sync-entry', {
            data: {entryId: button.dataset.shopifySync},
        })
            .then(({data}) => {
                Craft.cp.displaySuccess(data.message);
                button.textContent = Craft.t('craft-shopify-sync', 'Queued');
            })
            .catch(({response}) => {
                Craft.cp.displayError(response?.data?.message);
                button.disabled = false;
            })
            .finally(() => button.classList.remove('loading'));
    });

    // Dashboard: load live stats, and keep them current while the queue runs. Shopify's product count is only
    // fetched on load and once the queue is idle again, so polling doesn't use the sync's API budget.
    const dashboard = document.querySelector('[data-shopify-sync-dashboard]');

    if (dashboard && dashboard.dataset.ready === '1') {
        const connection = dashboard.querySelector('[data-connection]');
        const format = new Intl.NumberFormat(document.documentElement.lang);
        let timer = null;

        const refresh = (withShopify = true) => {
            clearTimeout(timer);

            Craft.sendActionRequest('GET', 'craft-shopify-sync/dashboard/stats', {params: {shopify: withShopify ? 1 : 0}})
                .then(({data}) => {
                    for (const [key, value] of Object.entries(data.stats)) {
                        const el = dashboard.querySelector(`[data-stat="${key}"]`);
                        if (el) {
                            el.textContent = value === null ? '–' : format.format(value);
                        }
                    }

                    if (data.shopifyError) {
                        connection.className = 'error with-icon';
                        connection.textContent = Craft.t('craft-shopify-sync', 'Can’t reach Shopify: {error}', {error: data.shopifyError});
                    } else if (withShopify) {
                        connection.className = 'light';
                        connection.innerHTML = dashboard.querySelector('[data-connected]').innerHTML;
                    }

                    if (document.hidden) {
                        return;
                    }

                    if (data.queueBusy) {
                        timer = setTimeout(() => refresh(false), 3000);
                    } else if (!withShopify) {
                        refresh();
                    }
                })
                .catch(() => {
                    connection.className = 'error with-icon';
                    connection.textContent = Craft.t('craft-shopify-sync', 'Couldn’t load live stats.');
                });
        };

        refresh();
        document.addEventListener('visibilitychange', () => document.hidden || refresh());
    }
})();
