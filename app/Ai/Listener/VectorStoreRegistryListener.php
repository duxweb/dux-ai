<?php

declare(strict_types=1);

namespace App\Ai\Listener;

use App\Ai\Event\VectorStoreEvent;
use App\Ai\Service\VectorStore\ChromaStore;
use App\Ai\Service\VectorStore\FileStore;
use App\Ai\Service\VectorStore\MongoStore;
use App\Ai\Service\VectorStore\MemoryStore;
use App\Ai\Service\VectorStore\QdrantStore;
use App\Ai\Service\VectorStore\RedisStore;
use Core\Event\Attribute\Listener;

class VectorStoreRegistryListener
{
    #[Listener(name: 'ai.vectorStore')]
    public function handle(VectorStoreEvent $event): void
    {
        $event->register('file', static fn (array $cfg) => new FileStore($cfg), [
            'label' => 'FileVectorStore',
            'value' => 'file',
            'form_schema' => [
                [
                    'tag' => 'div',
                    'attrs' => ['class' => 'flex flex-col gap-3'],
                    'children' => [
                        [
                            'tag' => 'dux-form-item',
                            'attrs' => ['label' => 'topK', 'tooltip' => '默认 4'],
                            'children' => [
                                ['tag' => 'n-input-number', 'attrs' => ['v-model:value.number' => 'options.topK', 'min' => 1, 'max' => 50]],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $event->register('memory', static fn (array $cfg) => new MemoryStore($cfg), [
            'label' => 'MemoryVectorStore',
            'value' => 'memory',
            'form_schema' => [
                [
                    'tag' => 'div',
                    'attrs' => ['class' => 'flex flex-col gap-3'],
                    'children' => [
                        [
                            'tag' => 'dux-form-item',
                            'attrs' => ['label' => 'topK', 'tooltip' => '默认 4（仅进程内）'],
                            'children' => [
                                ['tag' => 'n-input-number', 'attrs' => ['v-model:value.number' => 'options.topK', 'min' => 1, 'max' => 50]],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $event->register('qdrant', static fn (array $cfg) => new QdrantStore($cfg), [
            'label' => 'QdrantVectorStore',
            'value' => 'qdrant',
            'form_schema' => [
                [
                    'tag' => 'div',
                    'attrs' => ['class' => 'flex flex-col gap-3'],
                    'children' => [
                        [
                            'tag' => 'dux-form-item',
                            'attrs' => ['label' => 'collectionUrl', 'required' => true, 'tooltip' => '支持 {{knowledge_id}} 占位符'],
                            'children' => [
                                ['tag' => 'n-input', 'attrs' => ['v-model:value' => 'options.collectionUrl', 'placeholder' => 'http://localhost:6333/collections/rag_k{{knowledge_id}}']],
                            ],
                        ],
                        [
                            'tag' => 'dux-form-item',
                            'attrs' => ['label' => 'key', 'tooltip' => '可选'],
                            'children' => [
                                ['tag' => 'n-input', 'attrs' => ['v-model:value' => 'options.key', 'placeholder' => 'API Key（可选）']],
                            ],
                        ],
                        [
                            'tag' => 'dux-form-item',
                            'attrs' => ['label' => 'topK', 'tooltip' => '默认 4'],
                            'children' => [
                                ['tag' => 'n-input-number', 'attrs' => ['v-model:value.number' => 'options.topK', 'min' => 1, 'max' => 50]],
                            ],
                        ],
                        [
                            'tag' => 'dux-form-item',
                            'attrs' => ['label' => 'dimension', 'tooltip' => '默认 1536（可选）'],
                            'children' => [
                                ['tag' => 'n-input-number', 'attrs' => ['v-model:value.number' => 'options.dimension', 'min' => 1, 'max' => 100000]],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $event->register('chroma', static fn (array $cfg) => new ChromaStore($cfg), [
            'label' => 'ChromaVectorStore',
            'value' => 'chroma',
            'form_schema' => [
                [
                    'tag' => 'div',
                    'attrs' => ['class' => 'flex flex-col gap-3'],
                    'children' => [
                        [
                            'tag' => 'dux-form-item',
                            'attrs' => ['label' => 'collection', 'required' => true, 'tooltip' => '支持 {{knowledge_id}} 占位符'],
                            'children' => [
                                ['tag' => 'n-input', 'attrs' => ['v-model:value' => 'options.collection', 'placeholder' => 'rag_k{{knowledge_id}}']],
                            ],
                        ],
                        [
                            'tag' => 'dux-form-item',
                            'attrs' => ['label' => 'host', 'tooltip' => '默认 http://localhost:8000'],
                            'children' => [
                                ['tag' => 'n-input', 'attrs' => ['v-model:value' => 'options.host', 'placeholder' => 'http://localhost:8000']],
                            ],
                        ],
                        [
                            'tag' => 'dux-form-item',
                            'attrs' => ['label' => 'tenant', 'tooltip' => '默认 default_tenant'],
                            'children' => [
                                ['tag' => 'n-input', 'attrs' => ['v-model:value' => 'options.tenant', 'placeholder' => 'default_tenant']],
                            ],
                        ],
                        [
                            'tag' => 'dux-form-item',
                            'attrs' => ['label' => 'database', 'tooltip' => '默认 default_database'],
                            'children' => [
                                ['tag' => 'n-input', 'attrs' => ['v-model:value' => 'options.database', 'placeholder' => 'default_database']],
                            ],
                        ],
                        [
                            'tag' => 'dux-form-item',
                            'attrs' => ['label' => 'key', 'tooltip' => '可选'],
                            'children' => [
                                ['tag' => 'n-input', 'attrs' => ['v-model:value' => 'options.key', 'placeholder' => 'API Key（可选）']],
                            ],
                        ],
                        [
                            'tag' => 'dux-form-item',
                            'attrs' => ['label' => 'topK', 'tooltip' => '默认 5'],
                            'children' => [
                                ['tag' => 'n-input-number', 'attrs' => ['v-model:value.number' => 'options.topK', 'min' => 1, 'max' => 50]],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $event->register('redis', static fn (array $cfg) => new RedisStore($cfg), [
            'label' => 'RedisVectorStore',
            'value' => 'redis',
            'form_schema' => [
                [
                    'tag' => 'div',
                    'attrs' => ['class' => 'flex flex-col gap-3'],
                    'children' => [
                        [
                            'tag' => 'dux-form-item',
                            'attrs' => ['label' => 'DSN', 'tooltip' => '优先使用 DSN（如 redis://:pass@127.0.0.1:6379/0）'],
                            'children' => [
                                ['tag' => 'n-input', 'attrs' => ['v-model:value' => 'options.dsn', 'placeholder' => 'redis://:pass@127.0.0.1:6379/0']],
                            ],
                        ],
                        [
                            'tag' => 'dux-form-item',
                            'attrs' => ['label' => 'Host', 'tooltip' => '未填 DSN 时生效'],
                            'children' => [
                                ['tag' => 'n-input', 'attrs' => ['v-model:value' => 'options.host', 'placeholder' => '127.0.0.1']],
                            ],
                        ],
                        [
                            'tag' => 'dux-form-item',
                            'attrs' => ['label' => 'Port', 'tooltip' => '未填 DSN 时生效（默认 6379）'],
                            'children' => [
                                ['tag' => 'n-input-number', 'attrs' => ['v-model:value.number' => 'options.port', 'min' => 1, 'max' => 65535]],
                            ],
                        ],
                        [
                            'tag' => 'dux-form-item',
                            'attrs' => ['label' => 'Database', 'tooltip' => '未填 DSN 时生效（默认 0）'],
                            'children' => [
                                ['tag' => 'n-input-number', 'attrs' => ['v-model:value.number' => 'options.database', 'min' => 0, 'max' => 99]],
                            ],
                        ],
                        [
                            'tag' => 'dux-form-item',
                            'attrs' => ['label' => 'Password', 'tooltip' => '未填 DSN 时生效（可选）'],
                            'children' => [
                                ['tag' => 'n-input', 'attrs' => ['v-model:value' => 'options.password', 'type' => 'password']],
                            ],
                        ],
                        [
                            'tag' => 'dux-form-item',
                            'attrs' => ['label' => 'Index', 'tooltip' => '默认 rag_k{{knowledge_id}}'],
                            'children' => [
                                ['tag' => 'n-input', 'attrs' => ['v-model:value' => 'options.index', 'placeholder' => 'rag_k{{knowledge_id}}']],
                            ],
                        ],
                        [
                            'tag' => 'dux-form-item',
                            'attrs' => ['label' => 'Key Prefix', 'tooltip' => '默认 rag:{{knowledge_id}}:'],
                            'children' => [
                                ['tag' => 'n-input', 'attrs' => ['v-model:value' => 'options.prefix', 'placeholder' => 'rag:{{knowledge_id}}:']],
                            ],
                        ],
                        [
                            'tag' => 'dux-form-item',
                            'attrs' => ['label' => 'topK', 'tooltip' => '默认 4'],
                            'children' => [
                                ['tag' => 'n-input-number', 'attrs' => ['v-model:value.number' => 'options.topK', 'min' => 1, 'max' => 50]],
                            ],
                        ],
                        [
                            'tag' => 'dux-form-item',
                            'attrs' => ['label' => 'dimension', 'tooltip' => '可选（优先使用 Embeddings 模型维度）'],
                            'children' => [
                                ['tag' => 'n-input-number', 'attrs' => ['v-model:value.number' => 'options.dimension', 'min' => 1, 'max' => 100000]],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $event->register('mongodb', static fn (array $cfg) => new MongoStore($cfg), [
            'label' => 'MongoDBVectorStore',
            'value' => 'mongodb',
            'form_schema' => [
                [
                    'tag' => 'div',
                    'attrs' => ['class' => 'flex flex-col gap-3'],
                    'children' => [
                        [
                            'tag' => 'dux-form-item',
                            'attrs' => ['label' => 'URI', 'required' => true],
                            'children' => [
                                ['tag' => 'n-input', 'attrs' => ['v-model:value' => 'options.uri', 'placeholder' => 'mongodb://127.0.0.1:27017']],
                            ],
                        ],
                        [
                            'tag' => 'dux-form-item',
                            'attrs' => ['label' => 'Database', 'required' => true],
                            'children' => [
                                ['tag' => 'n-input', 'attrs' => ['v-model:value' => 'options.database', 'placeholder' => 'ai']],
                            ],
                        ],
                        [
                            'tag' => 'dux-form-item',
                            'attrs' => ['label' => 'Collection', 'tooltip' => '默认 rag_k{{knowledge_id}}'],
                            'children' => [
                                ['tag' => 'n-input', 'attrs' => ['v-model:value' => 'options.collection', 'placeholder' => 'rag_k{{knowledge_id}}']],
                            ],
                        ],
                        [
                            'tag' => 'dux-form-item',
                            'attrs' => ['label' => 'Index', 'required' => true, 'tooltip' => 'Atlas Vector Search 索引名'],
                            'children' => [
                                ['tag' => 'n-input', 'attrs' => ['v-model:value' => 'options.index', 'placeholder' => 'vector_index']],
                            ],
                        ],
                        [
                            'tag' => 'dux-form-item',
                            'attrs' => ['label' => 'Embedding Path', 'tooltip' => '默认 embedding'],
                            'children' => [
                                ['tag' => 'n-input', 'attrs' => ['v-model:value' => 'options.path', 'placeholder' => 'embedding']],
                            ],
                        ],
                        [
                            'tag' => 'dux-form-item',
                            'attrs' => ['label' => 'numCandidates', 'tooltip' => '默认 100（>= topK）'],
                            'children' => [
                                ['tag' => 'n-input-number', 'attrs' => ['v-model:value.number' => 'options.num_candidates', 'min' => 1, 'max' => 10000]],
                            ],
                        ],
                        [
                            'tag' => 'dux-form-item',
                            'attrs' => ['label' => 'topK', 'tooltip' => '默认 4'],
                            'children' => [
                                ['tag' => 'n-input-number', 'attrs' => ['v-model:value.number' => 'options.topK', 'min' => 1, 'max' => 50]],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }
}
