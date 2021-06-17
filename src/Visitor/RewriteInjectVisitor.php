<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace Hyperf\CodeGenerator\Visitor;

use Doctrine\Common\Annotations\Reader;
use Hyperf\CodeGenerator\Metadata;
use Hyperf\Di\Annotation\Inject;
use PhpDocReader\PhpDocReader;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use ReflectionClass;
use ReflectionProperty;

class RewriteInjectVisitor extends NodeVisitorAbstract
{
    public Reader $reader;

    public ReflectionClass $reflection;

    public PhpDocReader $docReader;

    public function __construct(public Metadata $metadata)
    {
        $this->reader = $this->metadata->reader;
        $this->docReader = $this->metadata->docReader;
        $this->reflection = new ReflectionClass($this->metadata->className);
    }

    public function leaveNode(Node $node)
    {
        switch ($node) {
            case $node instanceof Node\Stmt\Property:
                $property = $this->reflection->getProperty((string) $node->props[0]->name);
                $annotations = $this->reader->getPropertyAnnotations($property);
                foreach ($annotations as $annotation) {
                    if ($annotation instanceof Inject) {
                        $type = $node->type?->toString();
                        if (! $type) {
                            $type = $this->readTypeFromProperty($property);
                        }

                        if (! $type) {
                            continue;
                        }

                        $node->attrGroups[] = new Node\AttributeGroup([
                            new Node\Attribute(new Node\Name('Inject')),
                        ]);
                        $node->type = new Node\Name($type);
                        $node->setAttribute('comments', null);
                    }
                }

                return $node;
        }
    }

    protected function readTypeFromProperty(ReflectionProperty $property): ?string
    {
        $docComment = $property->getDocComment();
        if (! $docComment) {
            return null;
        }
        if (preg_match('/@var\s+([^\s]+)/', $docComment, $matches)) {
            [, $type] = $matches;
        } else {
            return null;
        }

        return $type;
    }
}