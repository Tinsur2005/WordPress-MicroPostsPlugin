<?php
/**
 * 微语页面模板
 *
 * 此模板用于在前台展示微语列表，用户创建页面时选择此模板即可自动渲染微语内容。
 *
 * @package WP_Weiyu_Plugin
 */

// 加载WordPress头部
get_header();

// 获取自定义页面标题
$custom_title = weiyu_get_page_title();

// 获取管理员信息（头像和昵称）
$admin_info = weiyu_get_admin_info();

// 获取当前页码，默认第1页
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;

// 每页显示的数量（最多6条）
$per_page = 6;

// 获取列表数据
$weiyu_data = (new WP_Weiyu_Plugin())->get_weiyu_list($current_page, $per_page);
$weiyu_list = $weiyu_data['list'];
$total_pages = $weiyu_data['total_pages'];
$total = $weiyu_data['total'];

// 获取页面标题
$page_title = get_the_title();

?>

<!-- 页面主体内容区域 -->
<div id="weiyu-container" class="weiyu-container">
    
    <!-- 页面标题区域 -->
    <div class="weiyu-header">
        <h1 class="weiyu-title"><?php echo esc_html(get_option('weiyu_page_title', '微语')); ?></h1>
        <p class="weiyu-subtitle"><?php echo esc_html(get_option('weiyu_page_subtitle', '记录生活的点点滴滴')); ?></p>
        <?php 
        // 获取页面浏览量
        $view_count = get_option('weiyu_page_views', 0);
        // 增加浏览量（打开页面一次就+1）
        update_option('weiyu_page_views', $view_count + 1);
        ?>
        <p class="weiyu-stats" data-view-count="<?php echo esc_attr($view_count); ?>">共 <?php echo esc_html($view_count); ?> 次浏览 | 共 <?php echo esc_html($total); ?> 条<?php echo esc_html($custom_title); ?></p>
        <?php 
        // 隐藏的总页数元素，供JavaScript使用
        if ($total_pages > 1) : ?>
            <div class="weiyu-total-pages" data-total-pages="<?php echo esc_attr($total_pages); ?>" style="display:none;"></div>
        <?php endif; ?>
    </div>

    <?php 
        // 如果管理员已登录，显示发布表单
        if (current_user_can('manage_options')) : 
        ?>
        <!-- 前台发布表单（仅管理员可见） -->
        <div class="weiyu-post-form">
            <h3>发布新<?php echo esc_html($custom_title); ?></h3>
            <form id="weiyu-frontend-form" method="post">
                <?php wp_nonce_field('weiyu_frontend_post', 'weiyu_frontend_nonce'); ?>
                <textarea id="weiyu-content-input" name="weiyu_content" placeholder="写下你想说的话..." rows="4" required></textarea>
                <button type="submit" id="weiyu-submit-btn" class="weiyu-submit-btn">发布<?php echo esc_html($custom_title); ?></button>
            </form>
            <div id="weiyu-post-message" class="weiyu-post-message"></div>
        </div>
        <?php endif; ?>

    <!-- 微语列表区域 -->
    <div class="weiyu-list">
        
        <?php if (!empty($weiyu_list)) : ?>
            <?php foreach ($weiyu_list as $weiyu) : ?>
                
                <!-- 单条微语卡片 -->
                <article class="weiyu-card">
                    <!-- 用户信息区域（昵称 + 时间） -->
                    <div class="weiyu-author">
                        <!-- 用户信息 -->
                        <div class="weiyu-meta">
                            <!-- 昵称 -->
                            <span class="weiyu-nickname"><?php echo esc_html($admin_info['nickname']); ?></span>
                            
                            <!-- 发布时间 -->
                            <span class="weiyu-time">
                                <?php 
                                date_default_timezone_set('Asia/Shanghai');
                                echo esc_html(date('Y年m月d日 H:i', strtotime($weiyu['created_at'])));
                                date_default_timezone_set(get_option('timezone_string'));
                                ?>
                            </span>
                        </div>
                    </div>
                    
                    <!-- 微语正文内容 -->
                    <div class="weiyu-content"><?php echo nl2br(esc_html($weiyu['content'])); ?></div>
                </article>
                
            <?php endforeach; ?>
            
        <?php else : ?>
            <!-- 空数据提示 -->
            <div class="weiyu-empty">
                <p>暂无<?php echo esc_html($custom_title); ?>，敬请期待</p>
            </div>
        <?php endif; ?>
        
    </div>
    
    <!-- 分页区域（放在列表外面，避免被AJAX替换） -->
    <?php if ($total_pages > 1) : ?>
        <div class="weiyu-pagination">
            <?php
            // 构建分页链接
            // 使用get_permalink确保分页URL正确，兼容自定义页面模板
            $pagination_args = array(
                'base' => get_permalink(get_the_ID()) . '%_%',
                'format' => '?paged=%#%',
                'total' => $total_pages,
                'current' => $current_page,
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
                'type' => 'list',
                'end_size' => 1,
                'mid_size' => 2
            );
            
            // 输出分页HTML
            echo paginate_links($pagination_args);
            ?>
        </div>
    <?php endif; ?>

</div>

<?php
// 加载WordPress尾部
get_footer();
?>