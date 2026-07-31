<?php
// +----------------------------------------------------------------------
// | 应用设置
// +----------------------------------------------------------------------
return [
    // 应用地址
    'app_host'         => env('app.host', ''),
    // 应用的命名空间
    'app_namespace'    => '',
    // 是否启用路由
    'with_route'       => true,
    // 应用快速访问（本项目为单应用结构，必须为 false，否则 think-multi-app 会把所有请求强转到不存在的 app/home）
    'app_express'      => false,
    // 默认应用
    'default_app'      => 'home',
    // 默认时区
    'default_timezone' => 'Asia/Shanghai',
    // 应用映射（自动多应用模式有效）
    'app_map' => [],
    // 域名绑定
    'domain_bind'      => [],
    // 禁止URL访问的应用列表
    'deny_app_list'    => [],
    // 异常页面的模板文件
    'exception_tmpl' => app()->getThinkPath() . 'tpl/think_exception.tpl',
    // 错误显示信息
    'error_message'    => '页面错误！请稍后再试～',
    // 显示错误信息
    'show_error_msg'   => false,
];
