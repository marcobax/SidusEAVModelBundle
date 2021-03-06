<?php
/*
 *  Sidus/EAVModelBundle : EAV Data management in Symfony 3
 *  Copyright (C) 2015-2017 Vincent Chalnot
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Sidus\EAVModelBundle\Cache;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\MappingException;
use Sidus\EAVModelBundle\Registry\FamilyRegistry;
use Sidus\EAVModelBundle\Model\AttributeInterface;
use Sidus\EAVModelBundle\Model\FamilyInterface;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * Cache annotation generator (using interfaces)
 * This is NOT code generation, the final result is only meant to aid the IDE to autocomplete magic methods
 * Do NOT use any of these interface outside of annotations.
 *
 * @author Vincent Chalnot <vincent@sidus.fr>
 */
class AnnotationGenerator implements CacheWarmerInterface
{
    /** @var FamilyRegistry */
    protected $familyRegistry;

    /** @var EntityManager */
    protected $manager;

    /** @var string */
    protected $annotationDir;

    /**
     * @param FamilyRegistry $familyRegistry
     * @param EntityManager  $manager
     * @param string         $varDir
     */
    public function __construct(FamilyRegistry $familyRegistry, EntityManager $manager, $varDir)
    {
        $this->familyRegistry = $familyRegistry;
        $this->manager = $manager;
        $this->annotationDir = $varDir.DIRECTORY_SEPARATOR.'annotations';
    }

    /**
     * Checks whether this warmer is optional or not.
     *
     * Optional warmers can be ignored on certain conditions.
     *
     * A warmer should return true if the cache can be
     * generated incrementally and on-demand.
     *
     * @return bool true if the warmer is optional, false otherwise
     */
    public function isOptional()
    {
        return false;
    }

    /**
     * Warms up the cache.
     *
     * @param string $cacheDir The cache directory
     *
     * @throws \RuntimeException
     */
    public function warmUp($cacheDir)
    {
        $baseDir = $this->annotationDir.DIRECTORY_SEPARATOR.'Sidus'.DIRECTORY_SEPARATOR.'EAV'.DIRECTORY_SEPARATOR;
        if (!@mkdir($baseDir, 0777, true) && !is_dir($baseDir)) {
            throw new \RuntimeException("Unable to create annotations directory: {$baseDir}");
        }

        foreach ($this->familyRegistry->getFamilies() as $family) {
            $content = $this->getFileHeader($family);

            foreach ($family->getAttributes() as $attribute) {
                $content .= $this->getAttributeMethods($family, $attribute);
            }

            $content .= "}\n";

            $this->writeFile($baseDir.$family->getCode().'.php', $content);
        }
    }

    /**
     * @param FamilyInterface $family
     *
     * @return string
     */
    protected function getFileHeader(FamilyInterface $family)
    {
        $content = <<<EOT
<?php

namespace Sidus\EAV;

abstract class {$family->getCode()} extends 
EOT;
        if ($family->getParent()) {
            $content .= $family->getParent()->getCode();
        } else {
            $content .= '\\'.$family->getDataClass();
        }
        $content .= "\n{\n";

        return $content;
    }

    /**
     * @param FamilyInterface    $family
     * @param AttributeInterface $attribute
     *
     * @return string
     */
    protected function getAttributeMethods(FamilyInterface $family, AttributeInterface $attribute)
    {
        if ($this->isAttributeInherited($family, $attribute)) {
            return '';
        }
        $content = '';
        $dataClass = new \ReflectionClass($family->getDataClass());

        $getter = 'get'.ucfirst($attribute->getCode());
        if (!$dataClass->hasMethod($getter)) {
            $content .= $this->generateGetAnnotation($family, $attribute);
            $content .= "abstract public function {$getter}(array \$context = null);\n\n";
        }

        $setter = 'set'.ucfirst($attribute->getCode());
        if (!$dataClass->hasMethod($setter)) {
            $content .= $this->generateSetAnnotation($family, $attribute);
            $content .= 'abstract public function set'.ucfirst($attribute->getCode());
            $content .= '($value, array $context = null);'."\n\n";
        }

        if ($attribute->isCollection()) {
            // Adder and remover
            $setter = 'add'.ucfirst($attribute->getCode());
            if (!$dataClass->hasMethod($setter)) {
                $content .= $this->generateSetAnnotation($family, $attribute, true);
                $content .= 'abstract public function add'.ucfirst($attribute->getCode());
                $content .= '($value, array $context = null);'."\n\n";
            }

            $setter = 'remove'.ucfirst($attribute->getCode());
            if (!$dataClass->hasMethod($setter)) {
                $content .= $this->generateSetAnnotation($family, $attribute, true);
                $content .= 'abstract public function remove'.ucfirst($attribute->getCode());
                $content .= '($value, array $context = null);'."\n\n";
            }
        }

        return $content;
    }

    /**
     * @param $filename
     * @param $content
     *
     * @throws \RuntimeException
     */
    protected function writeFile($filename, $content)
    {
        if (!@file_put_contents($filename, $content)) {
            throw new \RuntimeException("Unable to write annotation file: {$filename}");
        }
    }

    /**
     * @param FamilyInterface    $family
     * @param AttributeInterface $attribute
     * @param bool               $forceSingle
     *
     * @return string
     */
    protected function generateGetAnnotation(
        FamilyInterface $family,
        AttributeInterface $attribute,
        $forceSingle = false
    ) {
        $content = <<<EOT
/**
 * @param array|null \$context
 *
 * @return {$this->getPHPType($family, $attribute, $forceSingle)}
 */

EOT;

        return $content;
    }

    /**
     * @param FamilyInterface    $family
     * @param AttributeInterface $attribute
     * @param bool               $forceSingle
     *
     * @return string
     */
    protected function generateSetAnnotation(
        FamilyInterface $family,
        AttributeInterface $attribute,
        $forceSingle = false
    ) {
        $content = <<<EOT
/**
 * @param {$this->getPHPType($family, $attribute, $forceSingle)} \$value
 * @param array|null \$context
 *
 * @return {$family->getCode()}
 */

EOT;

        return $content;
    }

    /**
     * @param FamilyInterface    $family
     * @param AttributeInterface $attribute
     * @param bool               $forceSingle
     *
     * @return string
     */
    protected function getPHPType(
        FamilyInterface $family,
        AttributeInterface $attribute,
        $forceSingle = false
    ) {
        $type = substr($attribute->getType()->getDatabaseType(), 0, -strlen('Value'));
        $collection = $attribute->isCollection() && !$forceSingle;

        // Scalar types
        if (in_array($type, ['bool', 'integer', 'decimal', 'string', 'text'], true)) {
            if ($collection) {
                return 'array';
            }
            if ('text' === $type) {
                return 'string';
            } elseif ('decimal' === $type) {
                return 'double';
            }

            return $type;
        }
        if (in_array($type, ['date', 'datetime'], true)) {
            $type = '\DateTime';
            if ($collection) {
                $type .= '[]';
            }

            return $type;
        }
        if ('data' === $type) {
            $types = $attribute->getOption('allowed_families');
            if ($types) {
                if (!is_array($types)) {
                    $types = [$types];
                }
                if ($collection) {
                    /** @var array $types */
                    foreach ($types as &$type) {
                        $type .= '[]';
                    }
                }

                return implode('|', $types);
            }

            // Couldn't find any family (rare case)
            if ($collection) {
                return 'array';
            }

            return 'mixed';
        }

        // Then there are the custom relation cases:
        $type = $this->getTargetClass($family, $attribute, $forceSingle);
        if ($type) {
            return $type;
        }

        // Fallback in any other case
        if ($collection) {
            return 'array';
        }

        return 'mixed';
    }

    /**
     * @param FamilyInterface    $parentFamily
     * @param AttributeInterface $attribute
     * @param bool               $forceSingle
     *
     * @return string
     */
    protected function getTargetClass(
        FamilyInterface $parentFamily,
        AttributeInterface $attribute,
        $forceSingle = false
    ) {
        /** @var \Doctrine\ORM\Mapping\ClassMetadata $classMetadata */
        $classMetadata = $this->manager->getClassMetadata($parentFamily->getValueClass());
        try {
            $mapping = $classMetadata->getAssociationMapping($attribute->getType()->getDatabaseType());
        } catch (MappingException $e) {
            return null;
        }
        if (empty($mapping['targetEntity'])) {
            return null;
        }

        $type = $mapping['targetEntity'];
        if (!$forceSingle && $attribute->isCollection()) {
            $type .= '[]';
        }

        return '\\'.$type;
    }

    /**
     * @param FamilyInterface    $family
     * @param AttributeInterface $attribute
     *
     * @return bool
     */
    protected function isAttributeInherited(FamilyInterface $family, AttributeInterface $attribute)
    {
        if (!$family->getParent()) {
            return false;
        }
        if ($family->getParent()->hasAttribute($attribute->getCode())) {
            return true;
        }

        return $this->isAttributeInherited($family->getParent(), $attribute);
    }
}
