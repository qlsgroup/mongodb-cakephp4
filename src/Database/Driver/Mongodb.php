<?php

namespace Giginc\Mongodb\Database\Driver;

use Cake\Database\Query;
use Cake\Database\QueryCompiler;
use Cake\Database\Schema\TableSchema;
use Cake\Database\StatementInterface;
use Cake\Database\ValueBinder;
use Closure;
use Exception;
use Giginc\Mongodb\Database\MongoDb\Connection;
use Giginc\Mongodb\Exception\InvalidConnectionClassException;
use Giginc\Mongodb\Exception\SshExtensionNotEnabledException;
use Giginc\Mongodb\ORM\MongoStatement;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\Manager;
use MongoDB\Driver\ReadPreference;

/**
 * @method null getMaxAliasLength()
 * @method int getConnectRetries()
 * @method bool supports(string $feature)
 * @method bool inTransaction()
 * @method string getRole()
 */
class Mongodb
{
    /**
     * Config
     *
     * @access private
     */
    private array $_config;

    /**
     * Are we connected to the DataSource?
     *
     * true - yes
     * false - nope, we either failed to connect or have not started connecting
     *
     * @access public
     */
    public bool $connected = false;

    /**
     * Database Instance
     *
     * @access protected
     */
    protected Database|null $_db = null;

    /**
     * Mongo Driver Version
     *
     * @var string
     * @access protected
     */
    protected string $_driverVersion = MONGODB_VERSION;

    /**
     * Base Config
     *
     * set_string_id:
     *        true: In read() method, convert MongoDB\BSON\ObjectId object to string and set it to array 'id'.
     *        false: not convert and set.
     *
     * @access public
     *
     */
    protected array $_baseConfig = [
        'set_string_id' => true,
        'persistent' => true,
        'host'             => 'localhost',
        'database'     => '',
        'port'             => 27017,
        'login'     => '',
        'password'    => '',
        'replicaset'    => '',
    ];

    /**
     * Direct connection with database
     *
     * @access private
     */
    private Client|null $connection = null;

    public function __construct(array $config)
    {
        $this->_config = $config;
    }

    /**
     * return configuration
     *
     * @return array
     * @access public
     */
    public function config(): array
    {
        return $this->_config;
    }

    /**
     * connect to the database
     *
     * @return boolean
     * @access public
     */
    public function connect(): bool
    {
        try {
            if (($this->_config['ssh_user'] != '') && ($this->_config['ssh_host'])) { // Because a user is required for all the SSH authentication functions.
                if (!extension_loaded('ssh2')) {
                    throw new SshExtensionNotEnabledException();
                }

                if (intval($this->_config['ssh_port']) != 0) {
                    $port = $this->_config['ssh_port'];
                } else {
                    $port = 22; // The default SSH port.
                }

                // Spongebob will forever live on in our hearts
                $session = ssh2_connect($this->_config['ssh_host'], $port);
                if (!$session) {
                    trigger_error('Unable to establish a SSH connection to the host at '. $this->_config['ssh_host'] .':'. $port);
                }
                if (($this->_config['ssh_pubkey_path'] != null) && ($this->_config['ssh_privatekey_path'] != null)) {
                    if ($this->_config['ssh_pubkey_passphrase'] != null) {
                        if (!ssh2_auth_pubkey_file($session, $this->_config['ssh_user'], $this->_config['ssh_pubkey_path'], $this->_config['ssh_privatekey_path'], $this->_config['ssh_pubkey_passphrase'])) {
                            trigger_error('Unable to connect using the public keys specified at '. $this->_config['ssh_pubkey_path'] .' (for the public key), '. $this->_config['ssh_privatekey_path'] .' (for the private key) on '. $this->_config['ssh_user'] .'@'. $this->_config['ssh_host'] .':'. $port .' (Using a passphrase to decrypt the key)');
                            return false;
                        }
                    } else {
                        if (!ssh2_auth_pubkey_file($session, $this->_config['ssh_user'], $this->_config['ssh_pubkey_path'], $this->_config['ssh_privatekey_path'])) {
                            trigger_error('Unable to connect using the public keys specified at '. $this->_config['ssh_pubkey_path'] .' (for the public key), '. $this->_config['ssh_privatekey_path'] .' (for the private key) on '. $this->_config['ssh_user'] .'@'. $this->_config['ssh_host'] .':'. $port .' (Not using a passphrase to decrypt the key)');
                            return false;
                        }
                    }
                } elseif ($this->_config['ssh_password'] != '') { // While some people *could* have blank passwords, it's a really stupid idea.
                    if (!ssh2_auth_password($session, $this->_config['ssh_user'], $this->_config['ssh_password'])) {
                        trigger_error('Unable to connect using the username and password combination for '. $this->_config['ssh_user'] .'@'. $this->_config['ssh_host'] .':'. $port);
                        return false;
                    }
                } else {
                    trigger_error('Neither a password or paths to public & private keys were specified in the configuration.');
                    return false;
                }

                $tunnel = ssh2_tunnel($session, $this->_config['host'], $this->_config['port']);
                if (!$tunnel) {
                    trigger_error('A SSH tunnel was unable to be created to access '. $this->_config['host'] .':'. $this->_config['port'] .' on '. $this->_config['ssh_user'] .'@'. $this->_config['ssh_host'] .':'. $port);
                }
            }

            $host = $this->createConnectionName();

            if (version_compare($this->_driverVersion, '1.3.0', '<')) {
                throw new Exception(__("Please update your MongoDB PHP Driver ({0} < {1})", $this->_driverVersion, '1.3.0'));
            }

            if (isset($this->_config['replicaset']) && count($this->_config['replicaset']) === 2) {
                $this->connection = new \MongoDB\Client($this->_config['replicaset']['host'], $this->_config['replicaset']['options']);
            } else {
                $this->connection = new \MongoDB\Client($host);
            }

            if (isset($this->_config['slaveok'])) {
                $this->connection->getManager()->selectServer(
                    new ReadPreference($this->_config['slaveok']
                        ? ReadPreference::SECONDARY_PREFERRED
                        : ReadPreference::PRIMARY
                    )
                );
            }

            if ($this->_db = $this->connection->selectDatabase($this->_config['database'])) {
                $this->connected = true;
            }
        } catch (Exception $e) {
            trigger_error($e->getMessage());
        }

        return $this->connected;
    }

    /**
     * create connection string
     *
     * @access private
     * @return string
     */
    private function createConnectionName(): string
    {
        $host = '';

        if ($this->_driverVersion >= '1.0.2') {
            $host .= 'mongodb://';
        }
        $hostname = $this->_config['host'] . ':' . $this->_config['port'];

        if (!empty($this->_config['login'])) {
            $host .= $this->_config['login'] . ':' . $this->_config['password'] . '@' . $hostname . '/' . $this->_config['database'];
        } else {
            $host .= $hostname;
        }

        return $host;
    }

    /**
     * return MongoCollection object
     *
     * @param string $collectionName
     * @return Collection|bool
     * @access public
     */
    public function getCollection(string $collectionName = ''): Collection|bool
    {
        if (!empty($collectionName)) {
            if (!$this->isConnected()) {
                $this->connect();
            }

            $manager = new Manager($this->createConnectionName());
            return new Collection($manager, $this->_config['database'], $collectionName);
        }
        return false;
    }

    /**
     * @return string[]
     */
    public function listAllCollections(): array
    {
        $names = [];

        foreach ($this->_db->listCollectionNames() as $name) {
            $names[] = $name;
        }

        return $names;
    }

    /**
     * disconnect from the database
     *
     * @return boolean
     * @access public
     */
    public function disconnect(): void
    {
        if ($this->connected) {
            unset($this->_db, $this->connection);
        }
    }

    /*
     * database connection status
     *
     * @return bool
     * @access public
     */
    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * @return bool
     */
    public function enabled(): bool
    {
        return true;
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function setConnection($connection): void
    {
        if (!$connection instanceof Connection) {
            throw new InvalidConnectionClassException($connection::class);
        }

        $this->connection = $connection;
    }

    public function prepare($query): StatementInterface
    {
        return new MongoStatement($this);
    }

    public function beginTransaction(): bool
    {
        return false;
    }

    public function commitTransaction(): bool
    {
        return false;
    }

    public function rollbackTransaction(): bool
    {
        return false;
    }

    public function releaseSavePointSQL($name): string
    {
        return $name;
    }

    public function savePointSQL($name): string
    {
        return $name;
    }

    public function rollbackSavePointSQL($name): string
    {
        return $name;
    }

    public function disableForeignKeySQL(): string
    {
        return '';
    }

    public function enableForeignKeySQL(): string
    {
        return '';
    }

    public function supportsDynamicConstraints(): bool
    {
        return false;
    }

    public function supportsSavePoints(): bool
    {
        return false;
    }

    public function quote($value, $type): string
    {
        return $value;
    }

    public function supportsQuoting(): bool
    {
        return false;
    }

    public function queryTranslator(string $type): Closure
    {
        return function (string $query, array $params = []) use ($type) {

        };
    }

    public function quoteIdentifier(string $identifier): string
    {
        // TODO: Implement quoteIdentifier() method.
    }

    public function schemaValue($value): string
    {
        // TODO: Implement schemaValue() method.
    }

    public function schema(): string
    {
        // TODO: Implement schema() method.
    }

    public function lastInsertId(?string $table = null, ?string $column = null)
    {
        // TODO: Implement lastInsertId() method.
    }

    public function enableAutoQuoting(bool $enable = true)
    {
        // TODO: Implement enableAutoQuoting() method.
    }

    public function disableAutoQuoting()
    {
        // TODO: Implement disableAutoQuoting() method.
    }

    public function isAutoQuotingEnabled(): bool
    {
        // TODO: Implement isAutoQuotingEnabled() method.
    }

    public function compileQuery(Query $query, ValueBinder $binder): array
    {
        // TODO: Implement compileQuery() method.
    }

    public function newCompiler(): QueryCompiler
    {
        // TODO: Implement newCompiler() method.
    }

    public function newTableSchema(string $table, array $columns = []): TableSchema
    {
        // TODO: Implement newTableSchema() method.
    }

    public function __call(string $name, array $arguments)
    {
        // TODO: Implement @method null getMaxAliasLength()
        // TODO: Implement @method int getConnectRetries()
        // TODO: Implement @method bool supports(string $feature)
        // TODO: Implement @method bool inTransaction()
        // TODO: Implement @method string getRole()
    }
}
