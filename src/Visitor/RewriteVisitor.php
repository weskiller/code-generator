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
use Hyperf\Di\Annotation\AbstractAnnotation;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Utils\Str;
use PhpParser\BuilderFactory;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;

class RewriteVisitor extends NodeVisitorAbstract
{
    public Reader $reader;

    public ?ReflectionClass $reflection = null;

    public ?Node\Stmt\Namespace_ $namespace = null;

    public BuilderFactory $factory;

    /** @var Node\Stmt\UseUse[] */
    public array $uses = [];

    public function __construct(public Metadata $metadata, public array $annotations = [])
    {
        $this->reader = $this->metadata->reader;
        $this->factory = new BuilderFactory();
    }

    /**
     * @throws ReflectionException
     */
    public function enterNode(Node $node)
    {
        switch (true) {
            case $node instanceof Node\Stmt\Namespace_:
                $this->namespace = $node;
                break;
            case $node instanceof Node\Stmt\Class_:
                $this->reflection = new ReflectionClass(
                    $this->namespace->name . '\\' . $node->name
                );
                break;
            case $node instanceof Node\Stmt\Use_:
                foreach ($node->uses as $use) {
                    $this->uses[] = $use;
                }
                break;
        }
    }

    /**
     * @throws ReflectionException
     */
    public function leaveNode(Node $node): ?Node
    {
        if (! $this->reflection) {
            return $node;
        }
        return match ($node::class) {
            Node\Stmt\Class_::class => $this->generateClassAttributes(/* @var $node Node\Stmt\Class_ */ $node),
            Node\Stmt\ClassMethod::class => $this->generateClassMethodAttributes(/* @var $node Node\Stmt\ClassMethod */ $node),
            Node\Stmt\Property::class => $this->generateClassPropertyAttributes(/* @var $node Node\Stmt\Property */ $node),
            default => null,
        };
    }

    protected function generateClassAttributes(Node\Stmt\Class_ $node): Node\Stmt\Class_
    {
        $annotations = $this->reader->getClassAnnotations($this->reflection);
        return $this->generateAttributeAndSaveComments($node, $annotations);
    }

    /**
     * @throws ReflectionException
     */
    protected function generateClassMethodAttributes(Node\Stmt\ClassMethod $node): Node\Stmt\ClassMethod
    {
        $method = $this->reflection->getMethod((string) $node->name);
        return $this->generateAttributeAndSaveComments($node, $this->reader->getMethodAnnotations($method));
    }

    /**
     * @param Node\Stmt\Class_ $node
     * @return Node\Stmt\Class_|Node\Stmt\ClassMethod
     */
    protected function generateAttributeAndSaveComments(Node $node, array $annotations): Node\Stmt\Class_|Node\Stmt\ClassMethod
    {
        $comments = collect($node->getComments())->last()?->getText();
        foreach ($annotations as $annotation) {
            if (! in_array($annotation::class, $this->annotations, true)) {
                continue;
            }
            $className = $this->getClassName($annotation);
            $name = str_contains($className, '\\') ? new Node\Name\FullyQualified($className) : new Node\Name($this->getClassName($annotation));
            $node->attrGroups[] = new Node\AttributeGroup([
                new Node\Attribute(
                    $name,
                    $this->buildAttributeArgs($annotation),
                ),
            ]);
            $comments = $this->removeAnnotationFromComments($comments, $annotation);
            $this->metadata->setHandled(true);
        }
        $node->setDocComment(new Doc((string) $comments));
        return $node;
    }

    /**
     * @throws ReflectionException
     */
    protected function generateClassPropertyAttributes(Node\Stmt\Property $node): Node\Stmt\Property
    {
        $property = $this->reflection->getProperty((string) $node->props[0]->name);
        $annotations = $this->reader->getPropertyAnnotations($property);
        $comments = collect($node->getComments())->last()?->getText();
        /** @var AbstractAnnotation[] $annotations */
        foreach ($annotations as $annotation) {
            if (! in_array($annotation::class, $this->annotations, true)) {
                continue;
            }
            /** @var Node\Identifier|Node\Name $type */
            [$type,$comment] = $this->guessClassPropertyType($node, $property);
            if ($type) {
                if (
                    $comment                                                    // type is from comment like @var
                    && ($parentClass = $this->reflection->getParentClass())     // is subclass
                    && $parentClass->hasProperty($property->name)               // parentClass have same property
                    && ! $parentClass->getProperty($property->name)->hasType()   // parentClass property not have type, subclass same on
                ) {
                    if ($annotation instanceof Inject) {
                        $args = ['value' => $this->getInjectPropertyType($type)];
                    }
                } else {
                    $node->type = $type;
                }
                if ($comment) {
                    $comments = $this->removeAnnotationFromComments($comments, 'var');
                }
            }
            $node->attrGroups[] = new Node\AttributeGroup([
                new Node\Attribute(
                    $this->guessName($this->getClassName($annotation)),
                    $this->buildAttributeArgs($annotation, $args ?? []),
                ),
            ]);
            $comments = $this->removeAnnotationFromComments($comments, $annotation);
            $this->metadata->setHandled(true);
        }
        $node->setDocComment(new Doc((string) $comments));
        return $node;
    }

    protected function getInjectPropertyType(Node\Name $type): ?string
    {
        if (in_array($type->toString(), $this->baseType(), true)) {
            return $type->toString();
        }
        return match (true) {
            $type->isRelative(), $type->isQualified(), $type->isUnqualified() => $this->namespace->name->toString() . '\\'
                . $type->toString(),
            default => $type->toString(),
        };
    }

    protected function guessName(string $name): Node\Name
    {
        return str_contains($name, '\\') ? new Node\Name\FullyQualified($name) : new Node\Name($name);
    }

    protected function guessClassPropertyType(Node\Stmt\Property $node, ReflectionProperty $property): array
    {
        $fromComment = false;
        if ($node->type) {
            return [$node->type, $fromComment];
        }
        if ($type = $this->readTypeFromPropertyComment($property)) {
            $fromComment = true;
            if (str_ends_with($type, '[]')) {
                return [new Node\Name('array'), $fromComment];
            }
            if ($type !== 'callable') {
                return [$this->guessName($type), $fromComment];
            }
        }
        return [null, false];
    }

    protected function readTypeFromPropertyComment(ReflectionProperty $property): ?string
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

    protected function buildAttributeArgs(AbstractAnnotation $annotation, array $args = []): array
    {
        return $this->factory->args(array_merge($args, $this->getNotDefaultPropertyFromAnnotation($annotation)));
    }

    protected function getNotDefaultPropertyFromAnnotation(AbstractAnnotation $annotation): array
    {
        $properties = [];
        $ref = new ReflectionClass($annotation);
        foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->hasDefaultValue() && $property->getDefaultValue() === $property->getValue($annotation)) {
                continue;
            }
            $properties[$property->getName()] = $property->getValue($annotation);
        }
        return $properties;
    }

    protected function removeAnnotationFromComments(?string $comments, AbstractAnnotation|string $annotation): ?string
    {
        if (empty($comments)) {
            return $comments;
        }
        $reserved = [];
        $exclude = false;
        $class = sprintf('@%s', $this->getClassName($annotation));
        foreach (explode(PHP_EOL, $comments) as $comment) {
            if ($exclude === false && Str::startsWith(ltrim($comment, '\t\n\r\0\x0B* '), $this->compatibleFullyQualifiedClass($class))) {
                $exclude = true;
                continue;
            }
            $reserved[] = $comment;
        }
        if ($exclude === true && $this->isEmptyComments($reserved)) {
            return null;
        }
        return implode(PHP_EOL, $reserved);
    }

    protected function isEmptyComments(array $comments): bool
    {
        foreach ($comments as $comment) {
            if (preg_match('/^[\s*\/]*$/', $comment) === 0) {
                return false;
            }
        }
        return true;
    }

    protected function getClassName($class): string
    {
        $name = is_object($class) ? $class::class : $class;
        foreach ($this->uses as $use) {
            if ($name === $use->name->toString()) {
                if ($use->alias === null) {
                    return end($use->name->parts);
                }

                return $use->alias->toString();
            }
        }
        return $name;
    }

    protected function compatibleFullyQualifiedClass(string $class): array
    {
        if (Str::startsWith($class, '\\')) {
            return [$class, substr($class, 1)];
        }
        return [$class, '\\' . $class];
    }

    protected function baseType(): array
    {
        return [
            'bool',
            'int',
            'string',
            'object',
            'array',
        ];
    }
}
