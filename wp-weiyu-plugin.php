<?php
/**
 * WP微语插件
 *
 * @package   WP_Weiyu_Plugin
 * @author    Tinsur
 * @license   GPL-2.0+
 * @link      https://tinsur.cn
 * @copyright 2024 Tinsur
 *
 * @wordpress-plugin
 * Plugin Name: WP微语插件
 * Plugin URI:  https://example.com/wp-weiyu-plugin
 * Description: 轻量化个人微语/碎碎念发布插件，支持后台独立发布微语，前台专属页面优雅展示所有微语内容。
 * Version:     1.1
 * Author:      Tinsur
 * Author URI:  https://tinsur.cn
 * Text Domain: wp-weiyu-plugin
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 插件主类
 */
class WP_Weiyu_Plugin {

    /**
     * 数据表名称
     *
     * @var string
     */
    private $table_name;

    /**
     * 构造函数
     */
    public function __construct() {
        global $wpdb;
        
        // 设置数据表名称，前缀使用WP标准前缀
        $this->table_name = $wpdb->prefix . 'weiyu_posts';
        
        // 注册插件激活钩子
        register_activation_hook(__FILE__, array($this, 'activate'));
        
        // 注册插件停用钩子
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // 注册插件卸载钩子
        register_uninstall_hook(__FILE__, array('WP_Weiyu_Plugin', 'uninstall'));
        
        // 初始化后台功能
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // 初始化前台功能
        add_action('init', array($this, 'register_page_template'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // 注册AJAX端点
        add_action('wp_ajax_weiyu_load_page', array($this, 'ajax_load_page'));
        add_action('wp_ajax_nopriv_weiyu_load_page', array($this, 'ajax_load_page'));
        
        // 注册前台发布微语AJAX端点
        add_action('wp_ajax_weiyu_frontend_post', array($this, 'ajax_frontend_post'));
        add_action('wp_ajax_nopriv_weiyu_frontend_post', array($this, 'ajax_frontend_post'));
        
        // 注册表单处理钩子（使用admin_post方式，确保能正常重定向）
        add_action('admin_post_weiyu_save', array($this, 'handle_save_weiyu'));
    }

    /**
     * 插件激活时执行
     * 创建自定义数据表
     */
    public function activate() {
        global $wpdb;
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // 设置数据库字符集
        $charset_collate = $wpdb->get_charset_collate();
        
        // 创建数据表SQL语句
        $sql = "CREATE TABLE $this->table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            content text NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            modified_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        
        // 执行SQL创建表
        dbDelta($sql);
    }

    /**
     * 插件停用时执行
     * 可以在这里清理临时数据等
     */
    public function deactivate() {
        // 停用插件时无需特殊处理
    }

    /**
     * 插件卸载时执行
     * 删除自定义数据表和所有微语数据
     */
    public static function uninstall() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'weiyu_posts';
        
        // 删除数据表
        $wpdb->query("DROP TABLE IF EXISTS $table_name");
    }

    /**
     * 在后台添加微语管理菜单
     */
    public function add_admin_menu() {
        // 添加顶级菜单
        add_menu_page(
            '微语管理',           // 页面标题
            '微语管理',           // 菜单标题
            'manage_options',     // 权限要求（仅管理员可访问）
            'weiyu-manager',      // 菜单slug
            array($this, 'render_admin_page'),  // 回调函数
            'dashicons-admin-post',  // 菜单图标
            6                     // 菜单位置
        );
        
        // 添加子菜单（发布页面）
        add_submenu_page(
            'weiyu-manager',
            '发布微语',
            '发布微语',
            'manage_options',
            'weiyu-edit',
            array($this, 'render_edit_page')
        );
        
        // 添加设置子菜单
        add_submenu_page(
            'weiyu-manager',
            '微语设置',
            '设置',
            'manage_options',
            'weiyu-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * 渲染微语管理主页面
     */
    public function render_admin_page() {
        // 检查用户权限
        if (!current_user_can('manage_options')) {
            wp_die(__('您没有权限访问此页面。'));
        }
        
        global $wpdb;
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        
        // 处理删除操作（带nonce验证）
        if ($action === 'delete' && $id > 0) {
            // 验证nonce防止CSRF攻击
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'weiyu_delete_' . $id)) {
                wp_die(__('非法操作，请求已被拒绝。'));
            }
            $this->delete_weiyu($id);
            wp_redirect(admin_url('admin.php?page=weiyu-manager'));
            exit;
        }
        
        // 处理批量删除操作（带nonce验证）
        if (isset($_POST['delete_all']) && isset($_POST['weiyu_ids'])) {
            // 验证nonce防止CSRF攻击
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'weiyu_batch_delete')) {
                wp_die(__('非法操作，请求已被拒绝。'));
            }
            $weiyu_ids = array_map('intval', $_POST['weiyu_ids']);
            foreach ($weiyu_ids as $weiyu_id) {
                $this->delete_weiyu($weiyu_id);
            }
            wp_redirect(admin_url('admin.php?page=weiyu-manager'));
            exit;
        }
        
        // 获取所有微语，按时间倒序排列
        $weiyu_list = $wpdb->get_results(
            "SELECT * FROM $this->table_name ORDER BY created_at DESC",
            ARRAY_A
        );
        
        // 输出管理页面HTML
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">微语管理</h1>
            <a href="?page=weiyu-edit" class="page-title-action">发布新微语</a>
            <hr class="wp-header-end">
            
            <?php
            // 显示操作成功提示
            if (isset($_GET['message']) && $_GET['message'] === 'success') {
                ?>
                <div class="notice notice-success is-dismissible">
                    <p>微语已经发布完成！</p>
                </div>
                <?php
            } elseif (isset($_GET['message']) && $_GET['message'] === 'updated') {
                ?>
                <div class="notice notice-success is-dismissible">
                    <p>微语已经更新完成！</p>
                </div>
                <?php
            }
            ?>
            
            <?php if (!empty($weiyu_list)) : ?>
            <form method="post" action="">
                <?php wp_nonce_field('weiyu_batch_delete'); ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th scope="col" class="manage-column column-cb check-column">
                                <input type="checkbox" id="cb-select-all-1">
                            </th>
                            <th scope="col">微语内容</th>
                            <th scope="col">发布时间</th>
                            <th scope="col">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($weiyu_list as $weiyu) : ?>
                        <tr>
                            <th scope="row" class="check-column">
                                <input type="checkbox" name="weiyu_ids[]" value="<?php echo esc_attr($weiyu['id']); ?>">
                            </th>
                            <td><?php echo esc_html(mb_substr($weiyu['content'], 0, 100)); ?><?php if (mb_strlen($weiyu['content']) > 100) echo '...'; ?></td>
                            <td><?php 
                date_default_timezone_set('Asia/Shanghai');
                echo esc_html(date('Y-m-d H:i:s', strtotime($weiyu['created_at'])));
                date_default_timezone_set(get_option('timezone_string'));
            ?></td>
                            <td>
                                <a href="?page=weiyu-edit&amp;id=<?php echo esc_attr($weiyu['id']); ?>" class="edit">编辑</a>
                                |
                                <a href="?page=weiyu-manager&amp;action=delete&amp;id=<?php echo esc_attr($weiyu['id']); ?>&amp;_wpnonce=<?php echo esc_attr(wp_create_nonce('weiyu_delete_' . $weiyu['id'])); ?>" class="delete" onclick="return confirm('确定要删除这条微语吗？');">删除</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <p class="submit">
                    <input type="submit" name="delete_all" id="delete_all" class="button button-secondary" value="批量删除" onclick="return confirm('确定要删除选中的微语吗？');">
                </p>
            </form>
            <?php else : ?>
            <div class="notice notice-info">
                <p>暂无微语，点击上方"发布新微语"按钮发布第一条微语。</p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * 处理微语保存（使用admin_post方式，确保能正常重定向）
     */
    public function handle_save_weiyu() {
        // 验证nonce防止CSRF攻击
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'weiyu_save')) {
            wp_die(__('非法操作，请求已被拒绝。'));
        }
        
        // 检查用户权限
        if (!current_user_can('manage_options')) {
            wp_die(__('您没有权限访问此页面。'));
        }
        
        global $wpdb;
        $id = isset($_POST['weiyu_id']) ? intval($_POST['weiyu_id']) : 0;
        
        // 使用sanitize_textarea_field进行纯文本内容过滤，安全且统一
        $content = sanitize_textarea_field($_POST['weiyu_content']);
        
        // 合并日期和时间字段为完整的datetime字符串
        $date = isset($_POST['weiyu_date']) ? sanitize_text_field($_POST['weiyu_date']) : date('Y-m-d');
        $time = isset($_POST['weiyu_time']) ? sanitize_text_field($_POST['weiyu_time']) : date('H:i:s');
        $created_at = $date . ' ' . $time;
        
        if (!empty($content)) {
            if ($id > 0) {
                // 更新微语
                $wpdb->update(
                    $this->table_name,
                    array(
                        'content' => $content,
                        'created_at' => $created_at
                    ),
                    array('id' => $id),
                    array('%s', '%s'),
                    array('%d')
                );
                wp_safe_redirect(admin_url('admin.php?page=weiyu-manager&message=updated'));
            } else {
                // 新增微语
                $wpdb->insert(
                    $this->table_name,
                    array(
                        'content' => $content,
                        'created_at' => $created_at
                    ),
                    array('%s', '%s')
                );
                wp_safe_redirect(admin_url('admin.php?page=weiyu-manager&message=success'));
            }
        } else {
            // 内容为空，返回编辑页面
            $redirect_url = $id > 0 
                ? admin_url('admin.php?page=weiyu-edit&id=' . $id . '&message=empty')
                : admin_url('admin.php?page=weiyu-edit&message=empty');
            wp_safe_redirect($redirect_url);
        }
        
        exit;
    }
    
    /**
     * 渲染编辑页面
     */
    public function render_edit_page() {
        // 检查用户权限
        if (!current_user_can('manage_options')) {
            wp_die(__('您没有权限访问此页面。'));
        }
        
        global $wpdb;
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        $weiyu = null;
        
        // 如果是编辑模式，获取微语数据
        if ($id > 0) {
            $weiyu = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM $this->table_name WHERE id = %d", $id),
                ARRAY_A
            );
        }
        
        // 设置默认时间（使用北京时间）
        date_default_timezone_set('Asia/Shanghai');
        $default_date = $weiyu ? date('Y-m-d', strtotime($weiyu['created_at'])) : date('Y-m-d');
        $default_time = $weiyu ? date('H:i:s', strtotime($weiyu['created_at'])) : date('H:i:s');
        date_default_timezone_set(get_option('timezone_string'));
        
        // 输出编辑页面HTML
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php echo $id > 0 ? '编辑微语' : '添加新微语'; ?></h1>
            <a href="?page=weiyu-manager" class="page-title-action">返回微语列表</a>
            <hr class="wp-header-end">
            
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('weiyu_save'); ?>
                <input type="hidden" name="action" value="weiyu_save">
                <input type="hidden" name="weiyu_id" value="<?php echo esc_attr($id); ?>">
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="weiyu_content">微语内容</label>
                            </th>
                            <td>
                                <textarea name="weiyu_content" id="weiyu_content" rows="5" cols="50" class="large-text" required><?php echo $weiyu ? esc_textarea($weiyu['content']) : ''; ?></textarea>
                                <p class="description">支持文字、表情符号和简单换行，请勿输入恶意代码。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="weiyu_date">发布时间</label>
                            </th>
                            <td>
                                <input type="date" name="weiyu_date" id="weiyu_date" value="<?php echo esc_attr($default_date); ?>" class="regular-text">
                                <input type="time" name="weiyu_time" id="weiyu_time" value="<?php echo esc_attr($default_time); ?>" class="regular-text">
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <p class="submit">
                    <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php echo $id > 0 ? '更新微语' : '发布微语'; ?>">
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * 渲染设置页面
     */
    public function render_settings_page() {
        // 检查用户权限
        if (!current_user_can('manage_options')) {
            wp_die(__('您没有权限访问此页面。'));
        }
        
        // 处理设置保存
        if (isset($_POST['submit'])) {
            // 验证nonce防止CSRF攻击
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'weiyu_settings')) {
                wp_die(__('非法操作，请求已被拒绝。'));
            }
            
            // 保存设置
            update_option('weiyu_page_title', sanitize_text_field($_POST['weiyu_page_title']));
            update_option('weiyu_page_subtitle', sanitize_text_field($_POST['weiyu_page_subtitle']));
            
            // 显示保存成功提示
            ?>
            <div class="notice notice-success is-dismissible">
                <p>设置已保存！</p>
            </div>
            <?php
        }
        
        // 获取当前设置
        $page_title = get_option('weiyu_page_title', '微语');
        $page_subtitle = get_option('weiyu_page_subtitle', '记录生活的点点滴滴');
        
        // 输出设置页面HTML
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">微语设置</h1>
            <hr class="wp-header-end">
            
            <form method="post" action="">
                <?php wp_nonce_field('weiyu_settings'); ?>
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="weiyu_page_title">页面标题</label>
                            </th>
                            <td>
                                <input type="text" name="weiyu_page_title" id="weiyu_page_title" value="<?php echo esc_attr($page_title); ?>" class="regular-text">
                                <p class="description">前台微语页面显示的主标题</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="weiyu_page_subtitle">页面副标题</label>
                            </th>
                            <td>
                                <input type="text" name="weiyu_page_subtitle" id="weiyu_page_subtitle" value="<?php echo esc_attr($page_subtitle); ?>" class="regular-text">
                                <p class="description">前台微语页面标题下方显示的小字</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <p class="submit">
                    <input type="submit" name="submit" id="submit" class="button button-primary" value="保存设置">
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * 删除微语
     *
     * @param int $id 微语ID
     */
    private function delete_weiyu($id) {
        global $wpdb;
        $wpdb->delete($this->table_name, array('id' => $id), array('%d'));
    }

    /**
     * 注册页面模板
     */
    public function register_page_template() {
        // 通过add_filter注册页面模板
        add_filter('theme_page_templates', array($this, 'add_page_template'));
        add_filter('template_include', array($this, 'load_page_template'));
    }

    /**
     * 向主题页面模板列表添加微语页面模板
     *
     * @param array $templates 现有模板列表
     * @return array 更新后的模板列表
     */
    public function add_page_template($templates) {
        $templates['page-weiyu.php'] = '微语页面';
        return $templates;
    }

    /**
     * 加载微语页面模板
     *
     * @param string $template 当前模板路径
     * @return string 更新后的模板路径
     */
    public function load_page_template($template) {
        // 使用is_singular确保$post对象已初始化
        if (is_singular('page')) {
            global $post;
            $page_template = get_post_meta($post->ID, '_wp_page_template', true);
            
            if ($page_template === 'page-weiyu.php') {
                // 返回插件目录中的页面模板
                $plugin_template = plugin_dir_path(__FILE__) . 'page-weiyu.php';
                
                if (file_exists($plugin_template)) {
                    return $plugin_template;
                }
            }
        }
        
        return $template;
    }

    /**
     * 注册并加载前端样式和脚本
     */
    public function enqueue_scripts() {
        // 使用is_singular确保$post对象已初始化
        if (is_singular('page')) {
            global $post;
            
            // 获取页面模板
            $page_template = get_post_meta($post->ID, '_wp_page_template', true);
            
            // 只有在微语页面时才加载样式和脚本
            if ($page_template === 'page-weiyu.php') {
                // 注册并加载CSS样式
                wp_enqueue_style(
                    'weiyu-style',
                    plugin_dir_url(__FILE__) . 'weiyu-style.css',
                    array(),
                    '1.0.0',
                    'all'
                );
                
                // 注册并加载JavaScript脚本（依赖jQuery）
                wp_enqueue_script(
                    'weiyu-script',
                    plugin_dir_url(__FILE__) . 'weiyu-script.js',
                    array('jquery'),
                    '1.0.0',
                    true
                );
                
                // 获取管理员信息用于前端渲染
                $admin_info = weiyu_get_admin_info();
                
                // 传递数据到前端
                wp_localize_script('weiyu-script', 'weiyu_ajax', array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('weiyu_load_page'),
                    'frontend_nonce' => wp_create_nonce('weiyu_frontend_post'),
                    'nickname' => $admin_info['nickname'],
                    'page_title' => weiyu_get_page_title()
                ));
            }
        }
    }
    
    /**
     * AJAX加载微语列表
     */
    public function ajax_load_page() {
        // 验证nonce防止CSRF攻击
        if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'weiyu_load_page')) {
            wp_send_json_error('非法操作，请求已被拒绝。');
        }
        
        // 获取页码和每页数量
        $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
        $per_page = 6;
        
        // 获取微语数据
        $weiyu_data = $this->get_weiyu_list($page, $per_page);
        $weiyu_list = $weiyu_data['list'];
        $total_pages = $weiyu_data['total_pages'];
        $total = $weiyu_data['total'];
        
        // 格式化微语数据（添加格式化的时间）
        $formatted_list = array();
        date_default_timezone_set('Asia/Shanghai');
        foreach ($weiyu_list as $weiyu) {
            $formatted_list[] = array(
                'id' => $weiyu['id'],
                'content' => nl2br(esc_html($weiyu['content'])),
                'created_at' => date('Y年m月d日 H:i', strtotime($weiyu['created_at']))
            );
        }
        date_default_timezone_set(get_option('timezone_string'));
        
        // 构建分页HTML
        $pagination_html = $this->build_pagination_html($page, $total_pages);
        
        // 返回成功响应
        wp_send_json_success(array(
            'list' => $formatted_list,
            'pagination_html' => $pagination_html,
            'total' => $total,
            'current_page' => $page,
            'total_pages' => $total_pages
        ));
    }
    
    /**
     * AJAX前台发布微语
     */
    public function ajax_frontend_post() {
        // 验证权限
        if (!current_user_can('manage_options')) {
            wp_send_json_error('您没有权限执行此操作。');
        }
        
        // 验证nonce防止CSRF攻击
        if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'weiyu_frontend_post')) {
            wp_send_json_error('非法操作，请求已被拒绝。');
        }
        
        // 获取内容
        $content = isset($_POST['content']) ? trim(sanitize_textarea_field($_POST['content'])) : '';
        
        // 获取自定义页面标题
        $page_title = weiyu_get_page_title();
        
        // 验证内容
        if (empty($content)) {
            wp_send_json_error($page_title . '内容不能为空。');
        }
        
        // 获取当前北京时间作为发布时间
        date_default_timezone_set('Asia/Shanghai');
        $created_at = date('Y-m-d H:i:s');
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'weiyu_posts';
        
        // 插入数据
        $result = $wpdb->insert(
            $table_name,
            array(
                'content' => $content,
                'created_at' => $created_at
            ),
            array(
                '%s',
                '%s'
            )
        );
        
        if ($result) {
            $new_weiyu_id = $wpdb->insert_id;
            
            // 获取自定义页面标题
            $page_title = weiyu_get_page_title();
            
            // 获取管理员信息
            $admin_info = weiyu_get_admin_info();
            
            // 获取格式化的时间显示
            $formatted_time = date('Y年m月d日 H:i', strtotime($created_at));
            
            wp_send_json_success(array(
                'message' => $page_title . '发布成功！',
                'weiyu' => array(
                    'id' => $new_weiyu_id,
                    'content' => nl2br(esc_html($content)),
                    'created_at' => $created_at,
                    'formatted_time' => $formatted_time,
                    'nickname' => $admin_info['nickname']
                )
            ));
        } else {
            wp_send_json_error('发布失败，请重试。');
        }
    }
    
    /**
     * 构建分页HTML
     *
     * @param int $current_page 当前页码
     * @param int $total_pages 总页数
     * @return string 分页HTML
     */
    private function build_pagination_html($current_page, $total_pages) {
        if ($total_pages <= 1) {
            return '';
        }
        
        $html = '<ul>';
        
        // 上一页（与paginate_links保持一致，第一页不显示上一页按钮）
        if ($current_page > 1) {
            $html .= '<li><a href="?paged=' . ($current_page - 1) . '">&laquo;</a></li>';
        }
        
        // 页码列表
        for ($i = 1; $i <= $total_pages; $i++) {
            if ($i === $current_page) {
                $html .= '<li><span class="current">' . $i . '</span></li>';
            } else {
                $html .= '<li><a href="?paged=' . $i . '">' . $i . '</a></li>';
            }
        }
        
        // 下一页（与paginate_links保持一致，最后一页不显示下一页按钮）
        if ($current_page < $total_pages) {
            $html .= '<li><a href="?paged=' . ($current_page + 1) . '">&raquo;</a></li>';
        }
        
        $html .= '</ul>';
        
        return $html;
    }

    /**
     * 获取微语列表（带分页）
     *
     * @param int $page 当前页码
     * @param int $per_page 每页数量
     * @return array 微语列表和分页信息
     */
    public function get_weiyu_list($page = 1, $per_page = 10) {
        global $wpdb;
        
        // 计算偏移量
        $offset = ($page - 1) * $per_page;
        
        // 获取总记录数
        $total = $wpdb->get_var("SELECT COUNT(*) FROM $this->table_name");
        
        // 获取当前页微语列表
        $weiyu_list = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $this->table_name ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );
        
        // 计算总页数
        $total_pages = ceil($total / $per_page);
        
        return array(
            'list' => $weiyu_list,
            'total' => $total,
            'total_pages' => $total_pages,
            'current_page' => $page,
            'per_page' => $per_page
        );
    }
}

// 实例化插件主类
new WP_Weiyu_Plugin();

/**
 * 获取微语页面标题（支持自定义）
 *
 * @return string 页面标题
 */
function weiyu_get_page_title() {
    return get_option('weiyu_page_title', '微语');
}

/**
 * 获取网站管理员信息
 *
 * @return array 管理员信息（包含头像URL和昵称）
 */
function weiyu_get_admin_info() {
    // 获取网站管理员用户ID（默认第一个管理员）
    $admin_users = get_users(array('role' => 'administrator', 'number' => 1));
    
    if (!empty($admin_users)) {
        $admin = $admin_users[0];
        return array(
            'avatar' => get_avatar_url($admin->ID, array('size' => 80)),
            'nickname' => $admin->display_name
        );
    }
    
    // 如果没有管理员，返回默认信息
    return array(
        'avatar' => get_avatar_url(0, array('size' => 80)),
        'nickname' => get_option('blogname')
    );
}