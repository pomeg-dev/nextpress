/**
 * NextPress Block Preview Manager for ACF Blocks V3
 * Manages iframe-based block previews in the Gutenberg editor.
 */
window.NextPressBlockManager = window.NextPressBlockManager || (function() {
    var instances = {};
    var debounceTimers = {};
    var globalDebounceTimer = null;
    var isGlobalListenerSetup = false;

    function setupGlobalListeners() {
        if (isGlobalListenerSetup) return;
        isGlobalListenerSetup = true;

        // Global message listener for height adjustments
        window.addEventListener('message', function(event) {
            if (event.data && event.data.type === 'blockPreviewHeight' && event.data.iframeId) {
                var iframe = document.getElementById(event.data.iframeId);
                if (iframe) {
                    var newHeight = (event.data.height + 20) + 'px';
                    if (typeof event.data.height === 'string' && event.data.height.includes('vh')) {
                        newHeight = event.data.height;
                    }
                    iframe.style.height = newHeight;
                }
            }
        });

        // ACF V3 specific: Block re-renders happen automatically on change
        // We don't need manual field listeners because ACF V3 triggers PHP re-render
        // Our register() function will detect the new DOM and reload automatically
        if (window.acf) {
            acf.addAction('ready', function() {
                // Only listen to structural changes that need immediate updates
                acf.addAction('append', function() {
                    reloadAllVisible(800);
                });
                acf.addAction('remove', function() {
                    reloadAllVisible(800);
                });
                acf.addAction('sortstop', function() {
                    reloadAllVisible(800);
                });
            });
        }
    }

    function reloadAllVisible(delay) {
        clearTimeout(globalDebounceTimer);
        globalDebounceTimer = setTimeout(function() {
            Object.keys(instances).forEach(function(id) {
                if (instances[id].isVisible()) {
                    instances[id].reload();
                }
            });
        }, delay || 800);
    }

    function BlockInstance(iframeId) {
        this.iframeId = iframeId;
        this.iframe = null;
        this.loading = null;
        this.wrapper = null;
        this.lastLoadedHash = '';
        this.isLoading = false;
        this.initialized = false;

        this.init = function() {
            this.iframe = document.getElementById(this.iframeId);
            this.loading = document.getElementById('loading_' + this.iframeId);
            this.wrapper = document.getElementById('block_wrapper_' + this.iframeId);

            if (!this.iframe) return;

            setupGlobalListeners();

            // Check if content hash has changed (ACF V3 triggers re-render on field changes)
            var currentHash = this.iframe.getAttribute('data-content-hash');
            var isInitialized = this.iframe.getAttribute('data-initialized') === 'true';

            // Only skip if hash is same AND already initialized
            if (isInitialized && currentHash === this.lastLoadedHash) {
                return;
            }

            // Content changed or first load - reload iframe
            var self = this;
            setTimeout(function() {
                self.loadIframe();
            }, 150);
        };

        this.isVisible = function() {
            if (!this.wrapper) return false;
            var rect = this.wrapper.getBoundingClientRect();
            return rect.top < window.innerHeight && rect.bottom > 0;
        };

        this.loadIframe = function() {
            if (!this.iframe || this.isLoading) return;

            var currentHash = this.iframe.getAttribute('data-content-hash');

            // Skip if content hasn't changed and iframe is already loaded
            if (currentHash === this.lastLoadedHash && this.iframe.src) {
                if (this.iframe.style.display === 'none') {
                    this.iframe.style.display = 'block';
                    if (this.loading) this.loading.style.display = 'none';
                }
                return;
            }

            this.isLoading = true;
            this.lastLoadedHash = currentHash;

            var frontendUrl = this.iframe.getAttribute('data-frontend-url');
            var postId = this.iframe.getAttribute('data-post-id');
            var encodedContent = this.iframe.getAttribute('data-encoded-content');

            if (this.loading) {
                this.loading.style.display = 'flex';
                this.loading.innerHTML = '<span>Updating preview...</span>';
            }
            this.iframe.style.display = 'none';

            var self = this;
            var newSrc = frontendUrl + '/block-preview?post_id=' + postId + '&content=' + encodedContent + '&iframe_id=' + this.iframeId + '&t=' + Date.now();

            this.iframe.onload = function() {
                self.isLoading = false;
                self.iframe.style.display = 'block';
                self.iframe.setAttribute('data-initialized', 'true');
                if (self.loading) self.loading.style.display = 'none';
            };

            this.iframe.onerror = function() {
                self.isLoading = false;
                if (self.loading) {
                    self.loading.innerHTML = '<span style="color: #d63638;">Preview unavailable</span>';
                }
            };

            this.iframe.src = newSrc;

            // Timeout fallback
            setTimeout(function() {
                if (self.isLoading) {
                    self.isLoading = false;
                    self.iframe.style.display = 'block';
                    if (self.loading) self.loading.style.display = 'none';
                }
            }, 5000);
        };

        this.reload = function() {
            var self = this;
            clearTimeout(debounceTimers[this.iframeId]);
            debounceTimers[this.iframeId] = setTimeout(function() {
                self.lastLoadedHash = '';
                self.loadIframe();
            }, 300);
        };
    }

    return {
        register: function(iframeId) {
            // ACF V3 re-renders blocks, so we need to re-initialize even if instance exists
            if (instances[iframeId]) {
                setTimeout(function() {
                    instances[iframeId].init();
                }, 100);
            } else {
                instances[iframeId] = new BlockInstance(iframeId);
                setTimeout(function() {
                    instances[iframeId].init();
                }, 100);
            }
            return instances[iframeId];
        },
        getInstance: function(iframeId) {
            return instances[iframeId];
        }
    };
})();