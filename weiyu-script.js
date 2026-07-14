/**
 * WP微语插件 - 前端动态加载脚本
 * 
 * 功能：实现微语列表的AJAX动态分页，无需刷新页面
 */

(function($) {
    'use strict';
    
    /**
     * 前台发布微语功能
     */
    var WeiyuFrontendPost = {
        init: function() {
            this.cacheElements();
            this.bindEvents();
        },
        
        cacheElements: function() {
            this.$form = $('#weiyu-frontend-form');
            this.$textarea = $('#weiyu-content-input');
            this.$button = $('#weiyu-submit-btn');
            this.$message = $('#weiyu-post-message');
        },
        
        bindEvents: function() {
            var self = this;
            
            this.$form.on('submit', function(e) {
                e.preventDefault();
                self.submitForm();
            });
        },
        
        submitForm: function() {
            var self = this;
            var content = $.trim(this.$textarea.val());
            
            if (!content) {
                self.showMessage('请输入' + weiyu_ajax.page_title + '内容', 'error');
                return;
            }
            
            this.$button.prop('disabled', true).text('发布中...');
            
            $.ajax({
                url: weiyu_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'weiyu_frontend_post',
                    content: content,
                    security: weiyu_ajax.frontend_nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.showMessage(response.data.message, 'success');
                        self.$textarea.val('');
                        
                        // 将新内容添加到列表顶部
                        self.addNewWeiyu(response.data.weiyu);
                        
                        // 更新统计信息
                        self.updateStats();
                    } else {
                        self.showMessage(response.data, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('发布失败:', error);
                    self.showMessage('发布失败，请重试', 'error');
                },
                complete: function() {
                    self.$button.prop('disabled', false).text('发布' + weiyu_ajax.page_title);
                }
            });
        },
        
        showMessage: function(message, type) {
            var self = this;
            
            this.$message.html('<span class="' + type + '">' + message + '</span>');
            this.$message.fadeIn(300);
            
            setTimeout(function() {
                self.$message.fadeOut(300);
            }, 3000);
        },
        
        addNewWeiyu: function(weiyu) {
            var $list = $('.weiyu-list');
            var newCard = $('<article class="weiyu-card weiyu-card-animate" style="animation-delay: 0ms;">');
            
            newCard.html(
                '<div class="weiyu-author">' +
                '<div class="weiyu-meta">' +
                '<span class="weiyu-nickname">' + weiyu.nickname + '</span>' +
                '<span class="weiyu-time">' + weiyu.formatted_time + '</span>' +
                '</div>' +
                '</div>' +
                '<div class="weiyu-content">' + weiyu.content + '</div>' +
                '</article>'
            );
            
            $list.prepend(newCard);
            
            // 如果列表原来是空的，移除空提示
            $list.find('.weiyu-empty').remove();
        },
        
        updateStats: function() {
            var $stats = $('.weiyu-stats');
            if ($stats.length > 0) {
                var viewCount = $stats.data('view-count') || 0;
                var totalText = $stats.text();
                var match = totalText.match(/共 (\d+) 条/);
                var total = match ? parseInt(match[1], 10) + 1 : 1;
                
                $stats.html('共 ' + viewCount + ' 次浏览 | 共 ' + total + ' 条' + weiyu_ajax.page_title);
            }
        }
    };
    
    var WeiyuList = {
        currentPage: 1,
        totalPages: 1,
        isLoading: false,
        
        init: function() {
            this.cacheElements();
            this.bindEvents();
            
            var totalPagesEl = $('.weiyu-total-pages');
            if (totalPagesEl.length > 0) {
                this.totalPages = parseInt(totalPagesEl.data('total-pages'), 10);
            }
        },
        
        cacheElements: function() {
            this.$list = $('.weiyu-list');
            this.$pagination = $('.weiyu-pagination');
            this.$loading = $('.weiyu-loading');
        },
        
        bindEvents: function() {
            var self = this;
            
            $(document).on('click', '.weiyu-pagination a', function(e) {
                e.preventDefault();
                
                var href = $(this).attr('href');
                var page = self.getPageFromUrl(href);
                
                if (page && page !== self.currentPage && !self.isLoading) {
                    self.loadPage(page);
                }
            });
        },
        
        getPageFromUrl: function(url) {
            var match = url.match(/paged=(\d+)/);
            return match ? parseInt(match[1], 10) : null;
        },
        
        loadPage: function(page) {
            var self = this;
            
            self.isLoading = true;
            self.currentPage = page;
            
            self.showLoading();
            
            self.$list.fadeOut(300, function() {
                $.ajax({
                    url: weiyu_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'weiyu_load_page',
                        page: page,
                        security: weiyu_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            self.renderList(response.data.list);
                            self.renderPagination(response.data.pagination_html, response.data.current_page);
                            self.updateStats(response.data.total);
                            
                            self.$list.fadeIn(400);
                        } else {
                            console.error('加载微语失败:', response.data);
                            self.$list.fadeIn(300);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX请求失败:', error);
                        self.$list.fadeIn(300);
                    },
                    complete: function() {
                        self.isLoading = false;
                        self.hideLoading();
                        
                        $('html, body').animate({
                            scrollTop: $('.weiyu-container').offset().top - 60
                        }, 600, 'swing');
                    }
                });
            });
        },
        
        renderList: function(list) {
            var html = '';
            var nickname = weiyu_ajax.nickname;
            
            $.each(list, function(index, weiyu) {
                html += '<article class="weiyu-card weiyu-card-animate" style="animation-delay: ' + (index * 100) + 'ms;">';
                html += '<div class="weiyu-author">';
                html += '<div class="weiyu-meta">';
                html += '<span class="weiyu-nickname">' + nickname + '</span>';
                html += '<span class="weiyu-time">' + weiyu.created_at + '</span>';
                html += '</div>';
                html += '</div>';
                html += '<div class="weiyu-content">' + weiyu.content + '</div>';
                html += '</article>';
            });
            
            this.$list.html(html);
        },
        
        renderPagination: function(html, currentPage) {
            this.$pagination.html(html);
            
            this.$pagination.find('a').on('click', function(e) {
                e.preventDefault();
            });
        },
        
        updateStats: function(total) {
            var $stats = $('.weiyu-stats');
            if ($stats.length > 0) {
                var viewCount = $stats.data('view-count') || 0;
                $stats.html('共 ' + viewCount + ' 次浏览 | 共 ' + total + ' 条' + weiyu_ajax.page_title);
            }
        },
        
        showLoading: function() {
            if (this.$loading.length === 0) {
                this.$loading = $('<div class="weiyu-loading"><div class="weiyu-spinner"></div><span>加载中...</span></div>');
                this.$list.before(this.$loading);
            }
            this.$loading.fadeIn(200);
        },
        
        hideLoading: function() {
            if (this.$loading) {
                this.$loading.fadeOut(200);
            }
        }
    };
    
    $(document).ready(function() {
        WeiyuList.init();
        WeiyuFrontendPost.init();
    });
    
})(jQuery);