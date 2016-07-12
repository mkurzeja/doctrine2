<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\ORM\Tools;

use Doctrine\DBAL\Schema\Column;
use Doctrine\ORM\Mapping\FieldMetadata;
use Doctrine\ORM\ORMException;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\Visitor\DropSchemaSqlCollector;
use Doctrine\DBAL\Schema\Visitor\RemoveNamespacedAssets;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;

/**
 * The SchemaTool is a tool to create/drop/update database schemas based on
 * <tt>ClassMetadata</tt> class descriptors.
 *
 * @link    www.doctrine-project.org
 * @since   2.0
 * @author  Guilherme Blanco <guilhermeblanco@hotmail.com>
 * @author  Jonathan Wage <jonwage@gmail.com>
 * @author  Roman Borschel <roman@code-factory.org>
 * @author  Benjamin Eberlei <kontakt@beberlei.de>
 * @author  Stefano Rodriguez <stefano.rodriguez@fubles.com>
 */
class SchemaTool
{
    /**
     * @var \Doctrine\ORM\EntityManagerInterface
     */
    private $em;

    /**
     * @var \Doctrine\DBAL\Platforms\AbstractPlatform
     */
    private $platform;

    /**
     * The quote strategy.
     *
     * @var \Doctrine\ORM\Mapping\QuoteStrategy
     */
    private $quoteStrategy;

    /**
     * Initializes a new SchemaTool instance that uses the connection of the
     * provided EntityManager.
     *
     * @param \Doctrine\ORM\EntityManagerInterface $em
     */
    public function __construct(EntityManagerInterface $em)
    {
        $this->em               = $em;
        $this->platform         = $em->getConnection()->getDatabasePlatform();
        $this->quoteStrategy    = $em->getConfiguration()->getQuoteStrategy();
    }

    /**
     * Creates the database schema for the given array of ClassMetadata instances.
     *
     * @param array $classes
     *
     * @return void
     *
     * @throws ToolsException
     */
    public function createSchema(array $classes)
    {
        $createSchemaSql = $this->getCreateSchemaSql($classes);
        $conn = $this->em->getConnection();

        foreach ($createSchemaSql as $sql) {
            try {
                $conn->executeQuery($sql);
            } catch (\Exception $e) {
                throw ToolsException::schemaToolFailure($sql, $e);
            }
        }
    }

    /**
     * Gets the list of DDL statements that are required to create the database schema for
     * the given list of ClassMetadata instances.
     *
     * @param array $classes
     *
     * @return array The SQL statements needed to create the schema for the classes.
     */
    public function getCreateSchemaSql(array $classes)
    {
        $schema = $this->getSchemaFromMetadata($classes);

        return $schema->toSql($this->platform);
    }

    /**
     * Detects instances of ClassMetadata that don't need to be processed in the SchemaTool context.
     *
     * @param ClassMetadata $class
     * @param array         $processedClasses
     *
     * @return bool
     */
    private function processingNotRequired($class, array $processedClasses)
    {
        return (
            isset($processedClasses[$class->name]) ||
            $class->isMappedSuperclass ||
            $class->isEmbeddedClass ||
            ($class->isInheritanceTypeSingleTable() && $class->name !== $class->rootEntityName)
        );
    }

    /**
     * Creates a Schema instance from a given set of metadata classes.
     *
     * @param array $classes
     *
     * @return Schema
     *
     * @throws \Doctrine\ORM\ORMException
     */
    public function getSchemaFromMetadata(array $classes)
    {
        // Reminder for processed classes, used for hierarchies
        $processedClasses       = array();
        $eventManager           = $this->em->getEventManager();
        $schemaManager          = $this->em->getConnection()->getSchemaManager();
        $metadataSchemaConfig   = $schemaManager->createSchemaConfig();

        $metadataSchemaConfig->setExplicitForeignKeyIndexes(false);
        $schema = new Schema(array(), array(), $metadataSchemaConfig);

        $addedFks = array();
        $blacklistedFks = array();

        foreach ($classes as $class) {
            /** @var \Doctrine\ORM\Mapping\ClassMetadata $class */
            if ($this->processingNotRequired($class, $processedClasses)) {
                continue;
            }

            $table = $schema->createTable($this->quoteStrategy->getTableName($class, $this->platform));

            if ($class->isInheritanceTypeSingleTable()) {
                $this->gatherColumns($class, $table);
                $this->gatherRelationsSql($class, $table, $schema, $addedFks, $blacklistedFks);

                // Add the discriminator column
                $this->addDiscriminatorColumnDefinition($class, $table);

                // Aggregate all the information from all classes in the hierarchy
                foreach ($class->parentClasses as $parentClassName) {
                    // Parent class information is already contained in this class
                    $processedClasses[$parentClassName] = true;
                }

                foreach ($class->subClasses as $subClassName) {
                    $subClass = $this->em->getClassMetadata($subClassName);

                    $this->gatherColumns($subClass, $table);
                    $this->gatherRelationsSql($subClass, $table, $schema, $addedFks, $blacklistedFks);

                    $processedClasses[$subClassName] = true;
                }
            } elseif ($class->isInheritanceTypeJoined()) {
                // Add all non-inherited fields as columns
                $pkColumns = array();

                foreach ($class->getProperties() as $fieldName => $property) {
                    if (! $class->isInheritedProperty($fieldName)) {
                        $columnName = $this->platform->quoteIdentifier($property->getColumnName());

                        $this->gatherColumn($class, $property, $table);

                        if ($class->isIdentifier($fieldName)) {
                            $pkColumns[] = $columnName;
                        }
                    }
                }

                $this->gatherRelationsSql($class, $table, $schema, $addedFks, $blacklistedFks);

                // Add the discriminator column only to the root table
                if ($class->name === $class->rootEntityName) {
                    $this->addDiscriminatorColumnDefinition($class, $table);
                } else {
                    // Add an ID FK column to child tables
                    $inheritedKeyColumns = array();

                    foreach ($class->identifier as $identifierField) {
                        $idProperty = $class->getProperty($identifierField);

                        if ($class->isInheritedProperty($identifierField)) {
                            $column     = $this->gatherColumn($class, $idProperty, $table);
                            $columnName = $column->getQuotedName($this->platform);

                            // TODO: This seems rather hackish, can we optimize it?
                            $column->setAutoincrement(false);

                            $pkColumns[] = $columnName;
                            $inheritedKeyColumns[] = $columnName;
                        }
                    }

                    if ( ! empty($inheritedKeyColumns)) {
                        // Add a FK constraint on the ID column
                        $table->addForeignKeyConstraint(
                            $this->quoteStrategy->getTableName(
                                $this->em->getClassMetadata($class->rootEntityName),
                                $this->platform
                            ),
                            $inheritedKeyColumns,
                            $inheritedKeyColumns,
                            array('onDelete' => 'CASCADE')
                        );
                    }

                }

                $table->setPrimaryKey($pkColumns);
            } elseif ($class->isInheritanceTypeTablePerClass()) {
                throw ORMException::notSupported();
            } else {
                $this->gatherColumns($class, $table);
                $this->gatherRelationsSql($class, $table, $schema, $addedFks, $blacklistedFks);
            }

            $pkColumns = array();

            foreach ($class->identifier as $identifierField) {
                if (($property = $class->getProperty($identifierField)) !== null) {
                    $pkColumns[] = $this->platform->quoteIdentifier($property->getColumnName());

                    continue;
                }

                if (isset($class->associationMappings[$identifierField])) {
                    /* @var $assoc \Doctrine\ORM\Annotation\OneToOne */
                    $assoc = $class->associationMappings[$identifierField];

                    foreach ($assoc['joinColumns'] as $joinColumn) {
                        $pkColumns[] = $this->platform->quoteIdentifier($joinColumn['name']);
                    }
                }
            }

            if ( ! $table->hasIndex('primary')) {
                $table->setPrimaryKey($pkColumns);
            }

            // there can be unique indexes automatically created for join column
            // if join column is also primary key we should keep only primary key on this column
            // so, remove indexes overruled by primary key
            $primaryKey = $table->getIndex('primary');

            foreach ($table->getIndexes() as $idxKey => $existingIndex) {
                if ($primaryKey->overrules($existingIndex)) {
                    $table->dropIndex($idxKey);
                }
            }

            if (isset($class->table['indexes'])) {
                foreach ($class->table['indexes'] as $indexName => $indexData) {
                    if ( ! isset($indexData['flags'])) {
                        $indexData['flags'] = array();
                    }

                    $table->addIndex($indexData['columns'], is_numeric($indexName) ? null : $indexName, (array) $indexData['flags'], isset($indexData['options']) ? $indexData['options'] : array());
                }
            }

            if (isset($class->table['uniqueConstraints'])) {
                foreach ($class->table['uniqueConstraints'] as $indexName => $indexData) {
                    $uniqIndex = new Index($indexName, $indexData['columns'], true, false, [], isset($indexData['options']) ? $indexData['options'] : []);

                    foreach ($table->getIndexes() as $tableIndexName => $tableIndex) {
                        if ($tableIndex->isFullfilledBy($uniqIndex)) {
                            $table->dropIndex($tableIndexName);
                            break;
                        }
                    }

                    $table->addUniqueIndex($indexData['columns'], is_numeric($indexName) ? null : $indexName, isset($indexData['options']) ? $indexData['options'] : array());
                }
            }

            if (isset($class->table['options'])) {
                foreach ($class->table['options'] as $key => $val) {
                    $table->addOption($key, $val);
                }
            }

            $processedClasses[$class->name] = true;

            if ($class->isIdGeneratorSequence() && $class->name === $class->rootEntityName) {
                $definition = $class->sequenceGeneratorDefinition;
                $quotedName = $this->platform->quoteIdentifier($definition['sequenceName']);

                if ( ! $schema->hasSequence($quotedName)) {
                    $schema->createSequence($quotedName, $definition['allocationSize'], $definition['initialValue']);
                }
            }

            if ($eventManager->hasListeners(ToolEvents::postGenerateSchemaTable)) {
                $eventManager->dispatchEvent(
                    ToolEvents::postGenerateSchemaTable,
                    new GenerateSchemaTableEventArgs($class, $schema, $table)
                );
            }
        }

        if ( ! $this->platform->supportsSchemas() && ! $this->platform->canEmulateSchemas() ) {
            $schema->visit(new RemoveNamespacedAssets());
        }

        if ($eventManager->hasListeners(ToolEvents::postGenerateSchema)) {
            $eventManager->dispatchEvent(
                ToolEvents::postGenerateSchema,
                new GenerateSchemaEventArgs($this->em, $schema)
            );
        }

        return $schema;
    }

    /**
     * Gets a portable column definition as required by the DBAL for the discriminator
     * column of a class.
     *
     * @param ClassMetadata $class
     * @param Table         $table
     *
     * @return array The portable column definition of the discriminator column as required by
     *               the DBAL.
     */
    private function addDiscriminatorColumnDefinition($class, Table $table)
    {
        $discrColumn     = $class->discriminatorColumn;
        $discrColumnType = $discrColumn->getTypeName();
        $options         = array(
            'notnull' => ! $discrColumn->isNullable(),
        );

        switch ($discrColumnType) {
            case 'string':
                $options['length'] = $discrColumn->getLength() ?? 255;
                break;

            case 'decimal':
                $options['scale'] = $discrColumn->getScale();
                $options['precision'] = $discrColumn->getPrecision();
                break;
        }

        if (!empty($discrColumn->getColumnDefinition())) {
            $options['columnDefinition'] = $discrColumn->getColumnDefinition();
        }

        $table->addColumn($discrColumn->getColumnName(), $discrColumnType, $options);
    }

    /**
     * Gathers the column definitions as required by the DBAL of all field mappings
     * found in the given class.
     *
     * @param ClassMetadata $class
     * @param Table         $table
     *
     * @return array The list of portable column definitions as required by the DBAL.
     */
    private function gatherColumns($class, Table $table)
    {
        $pkColumns = array();

        foreach ($class->getProperties() as $fieldName => $property) {
            if ($class->isInheritanceTypeSingleTable() && $class->isInheritedProperty($fieldName)) {
                continue;
            }

            $this->gatherColumn($class, $property, $table);

            if ($property->isPrimaryKey()) {
                $pkColumns[] = $this->platform->quoteIdentifier($property->getColumnName());
            }
        }

        // For now, this is a hack required for single table inheritance, since this method is called
        // twice by single table inheritance relations
        if (!$table->hasIndex('primary')) {
            //$table->setPrimaryKey($pkColumns);
        }
    }

    /**
     * Creates a column definition as required by the DBAL from an ORM field mapping definition.
     *
     * @param ClassMetadata $classMetadata The class that owns the field mapping.
     * @param FieldMetadata $fieldMetadata The field mapping.
     * @param Table         $table
     *
     * @return Column The portable column definition as required by the DBAL.
     */
    private function gatherColumn($classMetadata, FieldMetadata $fieldMetadata, Table $table)
    {
        $fieldName  = $fieldMetadata->getName();
        $columnName = $fieldMetadata->getColumnName();
        $columnType = $fieldMetadata->getTypeName();

        $options = array(
            'length'          => $fieldMetadata->getLength(),
            'notnull'         => ! $fieldMetadata->isNullable(),
            'platformOptions' => array(
                'version' => ($classMetadata->isVersioned() && $classMetadata->versionProperty->getName() === $fieldName),
            ),
        );

        if ($classMetadata->isInheritanceTypeSingleTable() && count($classMetadata->parentClasses) > 0) {
            $options['notnull'] = false;
        }

        if (strtolower($columnType) === 'string' && null === $options['length']) {
            $options['length'] = 255;
        }

        if (is_int($fieldMetadata->getPrecision())) {
            $options['precision'] = $fieldMetadata->getPrecision();
        }

        if (is_int($fieldMetadata->getScale())) {
            $options['scale'] = $fieldMetadata->getScale();
        }

        if ($fieldMetadata->getColumnDefinition()) {
            $options['columnDefinition'] = $fieldMetadata->getColumnDefinition();
        }

        $fieldOptions = $fieldMetadata->getOptions();

        if ($fieldOptions) {
            $knownOptions = array('comment', 'unsigned', 'fixed', 'default');

            foreach ($knownOptions as $knownOption) {
                if (array_key_exists($knownOption, $fieldOptions)) {
                    $options[$knownOption] = $fieldOptions[$knownOption];

                    unset($fieldOptions[$knownOption]);
                }
            }

            $options['customSchemaOptions'] = $fieldOptions;
        }

        if ($classMetadata->isIdGeneratorIdentity() && $classMetadata->getIdentifierFieldNames() == array($fieldName)) {
            $options['autoincrement'] = true;
        }

        if ($classMetadata->isInheritanceTypeJoined() && $classMetadata->name !== $classMetadata->rootEntityName) {
            $options['autoincrement'] = false;
        }

        $quotedColumnName = $this->platform->quoteIdentifier($fieldMetadata->getColumnName());

        if ($table->hasColumn($quotedColumnName)) {
            // required in some inheritance scenarios
            $table->changeColumn($quotedColumnName, $options);

            $column = $table->getColumn($quotedColumnName);
        } else {
            $column = $table->addColumn($quotedColumnName, $columnType, $options);
        }

        if ($fieldMetadata->isUnique()) {
            $table->addUniqueIndex(array($columnName));
        }

        return $column;
    }

    /**
     * Gathers the SQL for properly setting up the relations of the given class.
     * This includes the SQL for foreign key constraints and join tables.
     *
     * @param ClassMetadata $class
     * @param Table         $table
     * @param Schema        $schema
     * @param array         $addedFks
     * @param array         $blacklistedFks
     *
     * @return void
     *
     * @throws \Doctrine\ORM\ORMException
     */
    private function gatherRelationsSql($class, $table, $schema, &$addedFks, &$blacklistedFks)
    {
        foreach ($class->associationMappings as $mapping) {
            if (isset($mapping['inherited'])) {
                continue;
            }

            $foreignClass = $this->em->getClassMetadata($mapping['targetEntity']);

            if ($mapping['type'] & ClassMetadata::TO_ONE && $mapping['isOwningSide']) {
                $primaryKeyColumns = array(); // PK is unnecessary for this relation-type

                $this->gatherRelationJoinColumns(
                    $mapping['joinColumns'],
                    $table,
                    $foreignClass,
                    $mapping,
                    $primaryKeyColumns,
                    $addedFks,
                    $blacklistedFks
                );
            } elseif ($mapping['type'] == ClassMetadata::ONE_TO_MANY && $mapping['isOwningSide']) {
                //... create join table, one-many through join table supported later
                throw ORMException::notSupported();
            } elseif ($mapping['type'] == ClassMetadata::MANY_TO_MANY && $mapping['isOwningSide']) {
                // create join table
                $joinTable = $mapping['joinTable'];

                $theJoinTable = $schema->createTable(
                    $this->quoteStrategy->getJoinTableName($mapping, $foreignClass, $this->platform)
                );

                $primaryKeyColumns = array();

                // Build first FK constraint (relation table => source table)
                $this->gatherRelationJoinColumns(
                    $joinTable['joinColumns'],
                    $theJoinTable,
                    $class,
                    $mapping,
                    $primaryKeyColumns,
                    $addedFks,
                    $blacklistedFks
                );

                // Build second FK constraint (relation table => target table)
                $this->gatherRelationJoinColumns(
                    $joinTable['inverseJoinColumns'],
                    $theJoinTable,
                    $foreignClass,
                    $mapping,
                    $primaryKeyColumns,
                    $addedFks,
                    $blacklistedFks
                );

                $theJoinTable->setPrimaryKey($primaryKeyColumns);
            }
        }
    }

    /**
     * Gets the class metadata that is responsible for the definition of the referenced column name.
     *
     * Previously this was a simple task, but with DDC-117 this problem is actually recursive. If its
     * not a simple field, go through all identifier field names that are associations recursively and
     * find that referenced column name.
     *
     * TODO: Is there any way to make this code more pleasing?
     *
     * @param ClassMetadata $class
     * @param string        $referencedColumnName
     *
     * @return array (ClassMetadata, referencedFieldName)
     */
    private function getDefiningClass($class, $referencedColumnName)
    {
        $referencedFieldName = $class->getFieldName($referencedColumnName);

        if ($class->hasField($referencedFieldName)) {
            return array($class, $referencedFieldName);
        }

        $idColumns        = $class->getIdentifierColumns($this->em);
        $idColumnNameList = array_keys($idColumns);

        if (in_array($referencedColumnName, $idColumnNameList)) {
            // it seems to be an entity as foreign key
            foreach ($class->getIdentifierFieldNames() as $fieldName) {
                if ($class->hasAssociation($fieldName)
                    && $class->getSingleAssociationJoinColumnName($fieldName) === $referencedColumnName) {
                    return $this->getDefiningClass(
                        $this->em->getClassMetadata($class->associationMappings[$fieldName]['targetEntity']),
                        $class->getSingleAssociationReferencedJoinColumnName($fieldName)
                    );
                }
            }
        }

        return null;
    }

    /**
     * Gathers columns and fk constraints that are required for one part of relationship.
     *
     * @param array         $joinColumns
     * @param Table         $theJoinTable
     * @param ClassMetadata $class
     * @param array         $mapping
     * @param array         $primaryKeyColumns
     * @param array         $addedFks
     * @param array         $blacklistedFks
     *
     * @return void
     *
     * @throws \Doctrine\ORM\ORMException
     */
    private function gatherRelationJoinColumns(
        $joinColumns,
        $theJoinTable,
        $class,
        $mapping,
        &$primaryKeyColumns,
        &$addedFks,
        &$blacklistedFks
    )
    {
        $localColumns       = array();
        $foreignColumns     = array();
        $fkOptions          = array();
        $foreignTableName   = $this->quoteStrategy->getTableName($class, $this->platform);
        $uniqueConstraints  = array();

        foreach ($joinColumns as $joinColumn) {
            list($definingClass, $referencedFieldName) = $this->getDefiningClass(
                $class,
                $joinColumn['referencedColumnName']
            );

            if ( ! $definingClass) {
                throw new \Doctrine\ORM\ORMException(sprintf(
                    'Column name "%s" referenced for relation from %s towards %s does not exist.',
                    $joinColumn['referencedColumnName'],
                    $mapping['sourceEntity'],
                    $mapping['targetEntity']
                ));
            }

            $quotedColumnName       = $this->platform->quoteIdentifier($joinColumn['name']);
            $quotedRefColumnName    = $this->platform->quoteIdentifier($joinColumn['referencedColumnName']);

            $primaryKeyColumns[]    = $quotedColumnName;
            $localColumns[]         = $quotedColumnName;
            $foreignColumns[]       = $quotedRefColumnName;

            if ( ! $theJoinTable->hasColumn($quotedColumnName)) {
                // Only add the column to the table if it does not exist already.
                // It might exist already if the foreign key is mapped into a regular
                // property as well.
                $property  = $definingClass->getProperty($referencedFieldName);
                $columnDef = null;

                if (isset($joinColumn['columnDefinition'])) {
                    $columnDef = $joinColumn['columnDefinition'];
                } elseif ($property->getColumnDefinition()) {
                    $columnDef = $property->getColumnDefinition();
                }

                $columnOptions = array('notnull' => false, 'columnDefinition' => $columnDef);
                $columnType    = $property->getTypeName();

                if (isset($joinColumn['nullable'])) {
                    $columnOptions['notnull'] = !$joinColumn['nullable'];
                }

                if ($property->getOptions()) {
                    $columnOptions['options'] = $property->getOptions();
                }

                switch ($columnType) {
                    case 'string':
                        $columnOptions['length'] = is_int($property->getLength()) ? $property->getLength() : 255;
                        break;

                    case 'decimal':
                        $columnOptions['scale'] = $property->getScale();
                        $columnOptions['precision'] = $property->getPrecision();
                        break;
                }

                $theJoinTable->addColumn($quotedColumnName, $columnType, $columnOptions);
            }

            if (isset($joinColumn['unique']) && $joinColumn['unique'] == true) {
                $uniqueConstraints[] = array('columns' => array($quotedColumnName));
            }

            if (isset($joinColumn['onDelete'])) {
                $fkOptions['onDelete'] = $joinColumn['onDelete'];
            }
        }

        // Prefer unique constraints over implicit simple indexes created for foreign keys.
        // Also avoids index duplication.
        foreach ($uniqueConstraints as $indexName => $unique) {
            $theJoinTable->addUniqueIndex($unique['columns'], is_numeric($indexName) ? null : $indexName);
        }

        $compositeName = $theJoinTable->getName().'.'.implode('', $localColumns);
        if (isset($addedFks[$compositeName])
            && ($foreignTableName != $addedFks[$compositeName]['foreignTableName']
            || 0 < count(array_diff($foreignColumns, $addedFks[$compositeName]['foreignColumns'])))
        ) {
            foreach ($theJoinTable->getForeignKeys() as $fkName => $key) {
                if (0 === count(array_diff($key->getLocalColumns(), $localColumns))
                    && (($key->getForeignTableName() != $foreignTableName)
                    || 0 < count(array_diff($key->getForeignColumns(), $foreignColumns)))
                ) {
                    $theJoinTable->removeForeignKey($fkName);
                    break;
                }
            }
            $blacklistedFks[$compositeName] = true;
        } elseif (!isset($blacklistedFks[$compositeName])) {
            $addedFks[$compositeName] = array('foreignTableName' => $foreignTableName, 'foreignColumns' => $foreignColumns);
            $theJoinTable->addUnnamedForeignKeyConstraint(
                $foreignTableName,
                $localColumns,
                $foreignColumns,
                $fkOptions
            );
        }
    }

    /**
     * Drops the database schema for the given classes.
     *
     * In any way when an exception is thrown it is suppressed since drop was
     * issued for all classes of the schema and some probably just don't exist.
     *
     * @param array $classes
     *
     * @return void
     */
    public function dropSchema(array $classes)
    {
        $dropSchemaSql = $this->getDropSchemaSQL($classes);
        $conn = $this->em->getConnection();

        foreach ($dropSchemaSql as $sql) {
            try {
                $conn->executeQuery($sql);
            } catch (\Exception $e) {

            }
        }
    }

    /**
     * Drops all elements in the database of the current connection.
     *
     * @return void
     */
    public function dropDatabase()
    {
        $dropSchemaSql = $this->getDropDatabaseSQL();
        $conn = $this->em->getConnection();

        foreach ($dropSchemaSql as $sql) {
            $conn->executeQuery($sql);
        }
    }

    /**
     * Gets the SQL needed to drop the database schema for the connections database.
     *
     * @return array
     */
    public function getDropDatabaseSQL()
    {
        $sm = $this->em->getConnection()->getSchemaManager();
        $schema = $sm->createSchema();

        $visitor = new DropSchemaSqlCollector($this->platform);
        $schema->visit($visitor);

        return $visitor->getQueries();
    }

    /**
     * Gets SQL to drop the tables defined by the passed classes.
     *
     * @param array $classes
     *
     * @return array
     */
    public function getDropSchemaSQL(array $classes)
    {
        $visitor = new DropSchemaSqlCollector($this->platform);
        $schema = $this->getSchemaFromMetadata($classes);

        $sm = $this->em->getConnection()->getSchemaManager();
        $fullSchema = $sm->createSchema();

        foreach ($fullSchema->getTables() as $table) {
            if ( ! $schema->hasTable($table->getName())) {
                foreach ($table->getForeignKeys() as $foreignKey) {
                    /* @var $foreignKey \Doctrine\DBAL\Schema\ForeignKeyConstraint */
                    if ($schema->hasTable($foreignKey->getForeignTableName())) {
                        $visitor->acceptForeignKey($table, $foreignKey);
                    }
                }
            } else {
                $visitor->acceptTable($table);
                foreach ($table->getForeignKeys() as $foreignKey) {
                    $visitor->acceptForeignKey($table, $foreignKey);
                }
            }
        }

        if ($this->platform->supportsSequences()) {
            foreach ($schema->getSequences() as $sequence) {
                $visitor->acceptSequence($sequence);
            }

            foreach ($schema->getTables() as $table) {
                /* @var $sequence Table */
                if ($table->hasPrimaryKey()) {
                    $columns = $table->getPrimaryKey()->getColumns();
                    if (count($columns) == 1) {
                        $checkSequence = $table->getName() . "_" . $columns[0] . "_seq";
                        if ($fullSchema->hasSequence($checkSequence)) {
                            $visitor->acceptSequence($fullSchema->getSequence($checkSequence));
                        }
                    }
                }
            }
        }

        return $visitor->getQueries();
    }

    /**
     * Updates the database schema of the given classes by comparing the ClassMetadata
     * instances to the current database schema that is inspected.
     *
     * @param array   $classes
     * @param boolean $saveMode If TRUE, only performs a partial update
     *                          without dropping assets which are scheduled for deletion.
     *
     * @return void
     */
    public function updateSchema(array $classes, $saveMode = false)
    {
        $updateSchemaSql = $this->getUpdateSchemaSql($classes, $saveMode);
        $conn = $this->em->getConnection();

        foreach ($updateSchemaSql as $sql) {
            $conn->executeQuery($sql);
        }
    }

    /**
     * Gets the sequence of SQL statements that need to be performed in order
     * to bring the given class mappings in-synch with the relational schema.
     *
     * @param array   $classes  The classes to consider.
     * @param boolean $saveMode If TRUE, only generates SQL for a partial update
     *                          that does not include SQL for dropping assets which are scheduled for deletion.
     *
     * @return array The sequence of SQL statements.
     */
    public function getUpdateSchemaSql(array $classes, $saveMode = false)
    {
        $sm = $this->em->getConnection()->getSchemaManager();

        $fromSchema = $sm->createSchema();
        $toSchema = $this->getSchemaFromMetadata($classes);

        $comparator = new Comparator();
        $schemaDiff = $comparator->compare($fromSchema, $toSchema);

        if ($saveMode) {
            return $schemaDiff->toSaveSql($this->platform);
        }

        return $schemaDiff->toSql($this->platform);
    }
}
