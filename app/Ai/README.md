## 模块简介

`Ai` 是当前项目的 AI 能力中心模块，负责统一管理服务商、模型、智能体、工作流、知识库、解析引擎、向量库、技能与相关运行记录。

它不是单一的“聊天模块”，而是一层把多种 AI 基础能力编排到同一后台中的集成模块：

- `AI` 负责把服务商配置转换成可运行的 LLM / Embeddings / 图片 / 视频 Provider
- `Parse` 负责 PDF、图片等文件解析驱动注册与调用
- `VectorStore` 负责向量库驱动注册与实例化
- `RagEngine` 负责知识库分片、向量化、入库、删除与检索
- `Agent / Flow / Capability / Scheduler` 负责智能体、流程执行、工具能力与异步任务编排

## 能力状态约定

- `稳定可用`：已经在当前模块主流程中承担核心职责
- `扩展入口`：推荐其他模块直接依赖的正式能力
- `接入前验证`：能力已开放，但强依赖具体模型、第三方服务或目标数据结构，正式接入前建议先做针对性验证

## 核心特点

- 统一管理 AI 服务商、模型、令牌、技能、智能体与工作流
- 支持多协议服务商接入，包括 OpenAI 兼容、ARK、BigModel、Moonshot 等
- 支持 Embeddings 模型选择、向量库配置与 RAG 检索链路
- 支持本地解析、百度 Paddle、Moonshot、BigModel、火山等解析驱动
- 支持知识库文件上传、内容分片、问答导入、向量入库与检索测试
- 支持智能体对话、流程运行、任务调度、视频任务轮询等异步能力
- 通过事件机制开放 Provider 协议、解析驱动、向量库驱动与能力注册入口

## 模块边界

- 适合放在 `Ai` 的能力：服务商协议适配、模型装配、AI 工作流、RAG、解析、向量库、智能体消息编排、AI 任务调度
- 不适合放在 `Ai` 的能力：业务模块自己的领域模型、业务专用审批规则、与 AI 无关的通用系统基础设施

## 对外开放的数据模型

```php
// 服务商、模型、令牌
App\Ai\Models\AiProvider
App\Ai\Models\AiModel
App\Ai\Models\AiToken

// 智能体、会话、消息、流程、流程日志
App\Ai\Models\AiAgent
App\Ai\Models\AiAgentSession
App\Ai\Models\AiAgentMessage
App\Ai\Models\AiFlow
App\Ai\Models\AiFlowLog
App\Ai\Models\AiFlowInterrupt

// 解析配置、向量库配置、知识库配置与内容
App\Ai\Models\ParseProvider
App\Ai\Models\AiVector
App\Ai\Models\RegProvider
App\Ai\Models\RagKnowledge
App\Ai\Models\RagKnowledgeData

// 调度与技能
App\Ai\Models\AiScheduler
App\Ai\Models\AiSkill

// 管理端页面路径、具体前端交互和日志文案
// 不建议作为跨模块稳定契约
```

## 对外开放的 Service

### AI

状态：`稳定可用`、`扩展入口`

```php
// App\Ai\Service\AI

// 根据 ai/model 配置构建聊天模型 Provider
forModel(AiModel $model, array $overrides = [], ?int $timeoutSeconds = null): AIProviderInterface

// 根据 ai/model 配置构建 Embeddings Provider
forEmbeddingsModel(AiModel $model): EmbeddingsProviderInterface

// 根据 ai/model 配置构建图片模型 Provider
forImageModel(AiModel $model, array $overrides = [], ?int $timeoutSeconds = null): AIProviderInterface

// 根据 ai/model 配置构建视频模型 Provider
forVideoModel(AiModel $model, array $overrides = [], ?int $timeoutSeconds = null): AIProviderInterface

// 直接从 ai/provider 配置构建 Provider
forProvider(AiProvider $provider, string $model, array $parameters = [], ?int $timeoutSeconds = null): AIProviderInterface

// 这是其他模块优先依赖的 AI 总入口
// 适合场景：聊天、Embeddings、图片、视频模型统一装配
// 不建议其他模块自己手工拼 URL、Header 和协议参数
```

### Parse

状态：`稳定可用`、`扩展入口`

```php
// App\Ai\Service\Parse

// 返回解析驱动注册表
registry(): array

// 读取某个解析驱动元信息
providerMeta(string $provider): array

// 按 ID、code 或模型对象解析 ParseProvider
resolveProvider(ParseProvider|string|int $identifier): ParseProvider

// 调用解析驱动解析文件
parseFile(ParseProvider|string|int $identifier, string $filePath, string $fileType, array $options = []): string

// 适合场景：
// - 聊天附件本地解析
// - 知识库文件预处理
// - 业务模块自己需要对 PDF / 图片做统一文本抽取
```

### VectorStore

状态：`稳定可用`、`扩展入口`、`接入前验证`

```php
// App\Ai\Service\VectorStore

// 返回向量库驱动注册表
registry(): array

// 读取某个向量库驱动元信息
driverMeta(string $driver): array

// 根据 ai/vector 配置实例化向量库
make(AiVector $vector, int $knowledgeId, ?int $dimensions = null): VectorStoreInterface

// 当前向量库驱动通过事件注册
// 适合在现有 AiVector 配置基础上复用
// 因为强依赖目标驱动与维度配置，正式接入前建议先验证一次入库与检索链路
```

### RagEngine

状态：`稳定可用`、`扩展入口`、`接入前验证`

```php
// App\Ai\Service\RagEngine

// 归一化知识库配置
normalizeConfig(array|null $config): array

// 保证知识库 remote/base_id 同步就绪
ensureSynced(RagKnowledge $knowledge): void

// 删除整个知识库对应的向量存储
deleteKnowledge(RegProvider $config, string $remoteId): bool

// 为一条知识库内容记录生成文档片段并写入向量库
addContent(RagKnowledge $knowledge, RagKnowledgeData $record, array $payload): string

// 导入问答并写入向量库
addQa(RagKnowledge $knowledge, RagKnowledgeData $record, array $qas, array $options = []): array

// 删除一组 source_id 对应的向量内容
deleteContent(RegProvider $config, string $remoteId, array $sourceIds): bool

// 执行知识库检索
query(RegProvider $config, string $remoteId, string $query, int $limit = 5, array $options = []): array

// 这是知识库接入的正式入口
// 适合场景：文档分片、向量入库、删除、检索
// 不建议跳过它直接拼 Embeddings 和向量库调用
```

### Rag

状态：`稳定可用`

```php
// App\Ai\Service\Rag\Service

// 主要服务于后台知识库管理、文件上传、CSV 问答导入和内容记录维护
// 更偏 Ai 模块内部业务编排
// 如果只是跨模块复用知识库检索或入库，优先依赖 RagEngine
```

### AIFlow

状态：`稳定可用`、`接入前验证`

```php
// App\Ai\Service\AIFlow\Service

// 工作流执行入口
// 负责把流程定义转换成可运行 Workflow，并接入持久化、节点执行和中断恢复
// 因为和当前流程节点体系耦合较深，跨模块直接复用前建议先验证目标流程模板
```

## 模块事件

### ProviderProtocolEvent

状态：`稳定可用`、`扩展入口`

```php
// 事件名
ai.provider.protocol

// 用途
// 给 ai/provider 扩展新的服务商协议元信息

// 默认监听器
App\Ai\Listener\ProviderProtocolRegistryListener
```

### ParseDriverEvent

状态：`稳定可用`、`扩展入口`

```php
// 事件名
ai.parse.driver

// 用途
// 注册新的解析驱动与表单元信息

// 默认监听器
App\Ai\Listener\ParseDriverRegistryListener
```

### VectorStoreEvent

状态：`稳定可用`、`扩展入口`

```php
// 事件名
ai.vectorStore

// 用途
// 注册新的向量库驱动

// 默认监听器
App\Ai\Listener\VectorStoreRegistryListener
```

### AiCapabilityEvent / AiFunctionEvent / ActionEvent

状态：`稳定可用`、`接入前验证`

```php
// 这些事件用于给智能体与工作流扩展能力、函数和动作

// 模块内已经有大量 Capability*Listener 作为内置能力实现，例如：
// - 文档解析
// - 指令执行
// - HTTP 请求
// - 知识库检索
// - 图片 / 视频任务
// - 延迟消息 / 异步等待

// 如果要给 AI 模块追加“可在工作流里被调用的新能力”
// 优先沿这套事件机制扩展
```

## 其他模块接入建议

```php
// 当前已内置的解析驱动：
// - local：本地解析，PDF 走 RapidOCRPDF，图片走 RapidOCR
// - baidu_paddle_cloud：百度 Paddle 云解析
// - moonshot：Moonshot 文件解析
// - volcengine_doc：火山文档解析
// - bigmodel：BigModel 文件解析同步接口
// 这些驱动统一通过 ParseFactory 管理，可继续通过事件 ai.parse.driver 扩展

// 当前已内置的向量库驱动：
// - file：本地文件向量存储
// - redis：Redis / RediSearch 向量存储
// - mongodb：MongoDB Atlas Vector Search 风格存储
// 它们统一通过 VectorStore Service 实例化，可继续通过事件 ai.vectorStore 扩展

// 常用日志通道：
// - ai.docs：文件解析、知识库文档处理日志
// - ai.rag：Embeddings 解析、向量检索与知识库链路日志
// - ai.agent：智能体、对话、工具调用相关日志
// - ai.image：图片模型相关日志
// - ai.video：视频模型与轮询任务相关日志
// 排查解析、知识库或向量入库问题时，优先看 data/logs/ 下这些通道

// 如果只是需要“调用某个模型”
// 优先依赖 AI::forModel()，不要自己管理协议适配

// 如果需要“把 PDF / 图片转文本”
// 优先依赖 Parse::parseFile()，不要自行分散接入多个 OCR 服务

// 如果需要“把业务文档做成语义知识库”
// 优先依赖 RagEngine，不要直接跳过分片与向量层

// 如果需要扩展新的向量库、解析驱动或服务商协议
// 优先注册事件，不要硬改核心工厂

// 如果接入的是第三方 AI / OCR / 向量服务
// 正式上线前应先在目标账号、目标模型、目标区域做一次真实链路验证
// 因为这些能力高度依赖供应商侧配置、额度与服务状态
```
