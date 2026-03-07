<h1 align="center">Dux AI</h1>

<p align="center">
  <strong>基于 Dux PHP Admin 的 AI 应用平台</strong>
</p>

<p align="center">
  把智能体、机器人、知识库、工作流与业务系统统一到一套中后台架构中，让 AI 真正能上线、能扩展、能交付。
</p>

<p align="center">
  <a href="https://ai.docs.dux.plus/" target="_blank">中文文档</a> |
  <a href="https://github.com/duxweb/dux-ai" target="_blank">GitHub</a> |
  <a href="https://github.com/duxweb/dux-php-admin" target="_blank">Dux PHP Admin</a>
</p>

<p align="center">
  <img alt="PHP Version" src="https://img.shields.io/badge/php-8.4+-blue.svg" />
  <img alt="License" src="https://img.shields.io/badge/License-MIT-green.svg" />
  <img alt="Version" src="https://img.shields.io/badge/version-v1.0-orange.svg" />
</p>

<p align="center">
<img src="https://ai.docs.dux.plus/hero.png" width="100%" />
</p>


## 项目定位

Dux AI 不是一个单独的聊天页面，而是一个面向真实业务系统的 AI 平台。

你可以把它理解成：

- 一套智能体平台
- 一套机器人接入平台
- 一套知识库与文件解析平台
- 一套工作流与异步任务平台
- 一套可以继续开发传统业务模块和 API 的中后台系统

它建立在 `Dux PHP Admin` 体系上，所以天然具备：

- 模块化扩展能力
- 后台页面与资源接口能力
- 菜单、权限、存储、调度等基础设施
- 继续扩展业务系统的能力

## 核心特性

- 智能体、机器人、知识库、工作流统一在一个平台里
- 智能体能力可持续扩展，支持 HTTP、函数、知识库、MCP、多媒体等能力
- 同步与异步任务都能处理，适合图片、视频、轮询和复杂自动化流程
- 支持文件解析、图片生成、视频生成等多媒体能力
- 支持机器人接入钉钉、飞书、QQ 机器人、企业微信等平台
- 适合与 CRM、OA、预约、电商、客服、医院等业务系统一起建设
- 同时适合传统 API、多端前端和中后台系统开发

## 适合什么团队

- 想给自己做一个 AI 助手的小团队
- 想把 AI 接入现有业务系统的企业团队
- 想做知识库问答、机器人服务、工作流自动化的产品团队
- 想把 AI 能力直接接进现有 PHP 中后台系统的开发团队

## 快速开始

### 环境要求

- PHP 8.4+
- MySQL 8.0+（推荐）
- Composer

### 安装项目

```bash
composer create-project duxweb/dux-ai
cd dux-ai
```

### 启动本地服务

```bash
php -S localhost:8000 -t public
```

访问安装向导：

```text
http://localhost:8000/
```

安装完成后进入后台：

```text
http://localhost:8000/manage/
```

## 正式部署要点

正式部署时，至少要注意这几件事：

- Web 根目录指向 `public`
- 配置标准 PHP 重写规则
- 在后台 `系统 -> 计划管理` 里添加 `AI Scheduler`
- 使用守护进程常驻运行：

```bash
php dux scheduler:run
```

更完整的部署文档请查看文档站中的“系统部署”章节。

## 文档导航

文档主要分为 3 部分：

- 使用：服务商、模型、智能体、机器人、知识库、工作流配置
- 开发：服务调用、能力扩展、驱动注册、工作流开发
- API：对外聊天接口与后台管理接口

## 相关项目

- Dux AI 文档：`https://github.com/duxweb/dux-ai-docs`
- Dux PHP Admin：`https://github.com/duxweb/dux-php-admin`
- Dux Lite：`https://lite2.docs.dux.plus/`
- DVHA：`https://dvha.docs.dux.plus/`

## 开源协议

本项目基于 MIT 协议开源。
