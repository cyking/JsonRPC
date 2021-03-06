<?php

namespace JsonRPC;

use Closure;
use BadFunctionCallException;
use Exception;
use InvalidArgumentException;
use LogicException;
use ReflectionFunction;
use ReflectionMethod;

class InvalidJsonRpcFormat extends Exception {};
class InvalidJsonFormat extends Exception {};


/**
 * JsonRPC CustomApplicationError class
 *
 * @package JsonRPC
 * @author  cyking
 * @license Unlicense http://unlicense.org/
 */
class CustomApplicationError extends Exception
{
    private $customAppError;

    public function __construct($message, $code = 0, $customAppError = array(), Exception $previous = null)
    {
        $this->setCustomAppError($customAppError);
        parent::__construct($message, $code, $previous);
    }

    public function __toString()
    {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }

    public function setCustomAppError(array $customAppError)
    {
        $this->customAppError = $customAppError;
    }

    public function getCustomAppError()
    {
        return $this->customAppError;
    }
};


/**
 * JsonRPC server class
 *
 * @package JsonRPC
 * @author  Frederic Guillot
 * @license Unlicense http://unlicense.org/
 */
class Server
{
    /**
     * Data received from the client
     *
     * @access private
     * @var string
     */
    private $payload;

    /**
     * List of procedures
     *
     * @static
     * @access private
     * @var array
     */
    private $callbacks = array();

    /**
     * List of classes
     *
     * @static
     * @access private
     * @var array
     */
    private $classes = array();

    /**
     * List of instances
     *
     * @static
     * @access private
     * @var array
     */
    private $instances = array();

    /**
     * autodetect output for "error" element array
     *
     * @access public
     * @var boolean
     */
    public $detect_output_error = false;

    /**
     * Constructor
     *
     * @access public
     * @param  string   $payload      Client data
     * @param  array    $callbacks    Callbacks
     * @param  array    $classes      Classes
     */
    public function __construct($payload = '', array $callbacks = array(), array $classes = array())
    {
        $this->payload = $payload;
        $this->callbacks = $callbacks;
        $this->classes = $classes;
    }

    /**
     * IP based client restrictions
     *
     * Return an HTTP error 403 if the client is not allowed
     *
     * @access public
     * @param  array   $hosts   List of hosts
     */
    public function allowHosts(array $hosts) {

        if (! in_array($_SERVER['REMOTE_ADDR'], $hosts)) {

            header('Content-Type: application/json');
            header('HTTP/1.0 403 Forbidden');
            echo '{"error": "Access Forbidden"}';
            exit;
        }
    }

    /**
     * HTTP Basic authentication
     *
     * Return an HTTP error 401 if the client is not allowed
     *
     * @access public
     * @param  array   $users   Map of username/password
     */
    public function authentication(array $users)
    {
        if (! isset($_SERVER['PHP_AUTH_USER']) ||
            ! isset($users[$_SERVER['PHP_AUTH_USER']]) ||
            $users[$_SERVER['PHP_AUTH_USER']] !== $_SERVER['PHP_AUTH_PW']) {

            header('WWW-Authenticate: Basic realm="JsonRPC"');
            header('Content-Type: application/json');
            header('HTTP/1.0 401 Unauthorized');
            echo '{"error": "Authentication failed"}';
            exit;
        }
    }

    /**
     * Register a new procedure
     *
     * @access public
     * @param  string   $procedure       Procedure name
     * @param  closure  $callback        Callback
     */
    public function register($name, Closure $callback)
    {
        $this->callbacks[$name] = $callback;
    }

    /**
     * Bind a procedure to a class
     *
     * @access public
     * @param  string   $procedure    Procedure name
     * @param  mixed    $class        Class name or instance
     * @param  string   $method       Procedure - if null will populate with $procedure
     */
    public function bind($procedure, $class, $method=null)
    {
        if ($method === null) {
            $method = $procedure;
        }

        $this->classes[$procedure] = array($class, $method);
    }

    /**
     * Bind a class instance
     *
     * @access public
     * @param  mixed   $instance    Instance name
     */
    public function attach($instance)
    {
        $this->instances[] = $instance;
    }

    /**
     * Return the response to the client
     *
     * @access public
     * @param  array    $data      Data to send to the client
     * @param  array    $payload   Incoming data
     * @return string
     */
    public function getResponse(array $data, array $payload = array())
    {
        if (! array_key_exists('id', $payload)) {
            return '';
        }

        $response = array(
            'jsonrpc' => '2.0',
            'id' => $payload['id']
        );

        $response = array_merge($response, $data);

        @header('Content-Type: application/json');
        return json_encode($response);
    }

    /**
     * Parse the payload and test if the parsed JSON is ok
     *
     * @access public
     */
    public function checkJsonFormat()
    {
        if (empty($this->payload)) {
            $this->payload = file_get_contents('php://input');
        }

        if (is_string($this->payload)) {
            $this->payload = json_decode($this->payload, true);
        }

        if (! is_array($this->payload)) {
            throw new InvalidJsonFormat('Malformed payload');
        }
    }

    /**
     * Test if all required JSON-RPC parameters are here
     *
     * @access public
     */
    public function checkRpcFormat()
    {
        if (! isset($this->payload['jsonrpc']) ||
            ! isset($this->payload['method']) ||
            ! is_string($this->payload['method']) ||
            $this->payload['jsonrpc'] !== '2.0' ||
            (isset($this->payload['params']) && ! is_array($this->payload['params']))) {

            throw new InvalidJsonRpcFormat('Invalid JSON RPC payload');
        }
    }

    /**
     * Return true if we have a batch request
     *
     * @access public
     * @return boolean
     */
    private function isBatchRequest()
    {
        return array_keys($this->payload) === range(0, count($this->payload) - 1);
    }

    /**
     * Handle batch request
     *
     * @access private
     * @return string
     */
    private function handleBatchRequest()
    {
        $responses = array();

        foreach ($this->payload as $payload) {

            if (! is_array($payload)) {

                $responses[] = $this->getResponse(array(
                    'error' => array(
                        'code' => -32600,
                        'message' => 'Invalid Request'
                    )),
                    array('id' => null)
                );
            }
            else {

                $server = new Server($payload, $this->callbacks, $this->classes);
                $response = $server->execute();

                if ($response) {
                    $responses[] = $response;
                }
            }
        }

        return empty($responses) ? '' : '['.implode(',', $responses).']';
    }


    /**
     * Make sure error follows http://www.jsonrpc.org/specification - section 5.1
     *
     * @access public
     * @param  array    $error       error array to validate.
     * @return array
     */
    public function validateError($error)
    {
        $validError =  array();

        if (isset($error['code']) === true)
        {
            $validError['code'] = intval($error['code']);
        }
        else {
            $validError['code']  = -32500;                          // error code must exist - default to application error.
        }

        if (isset($error['message']) === true) {
            $validError['message'] = $error['message'];
        }
        else {
            $validError['message'] = 'application error detected';  // must have an error message.
        }

        if (isset($error['data']) === true) {
            $validError['data'] = $error['data'];    // data is optional.
        }

        return $validError;
    }


    /**
     * Parse incoming requests
     *
     * @access public
     * @return string
     */
    public function execute()
    {
        try {

            $this->checkJsonFormat();

            if ($this->isBatchRequest()){
                return $this->handleBatchRequest();
            }

            $this->checkRpcFormat();

            $result = $this->executeProcedure(
                $this->payload['method'],
                empty($this->payload['params']) ? array() : $this->payload['params']
            );

            if ($this->detect_output_error === true && isset($result['error']) === true) {
                $detected_error = $this->validateError($result['error']);

                return $this->getResponse(array(
                    'error' => $detected_error),
                    array('id' => null)
                );
            }

            return $this->getResponse(array('result' => $result), $this->payload);
        }
        catch (JsonRPC\CustomApplicationError $e) {
            $error = validateError($e->getCustomAppError['error']);

            return $this->getResponse(array(
                'error' => $error),
                array('id' => null)
            );

        }
        catch (InvalidJsonFormat $e) {

            return $this->getResponse(array(
                'error' => array(
                    'code' => -32700,
                    'message' => 'Parse error',
                    'data' => $e->getMessage()
                )),
                array('id' => null)
            );
        }
        catch (InvalidJsonRpcFormat $e) {

            return $this->getResponse(array(
                'error' => array(
                    'code' => -32600,
                    'message' => 'Invalid Request',
                    'data' => $e->getMessage()
                )),
                array('id' => null)
            );
        }
        catch (BadFunctionCallException $e) {

            return $this->getResponse(array(
                'error' => array(
                    'code' => -32601,
                    'message' => 'Method not found',
                    'data' => $e->getMessage()
                )),
                $this->payload
            );
        }
        catch (InvalidArgumentException $e) {

            return $this->getResponse(array(
                'error' => array(
                    'code' => -32602,
                    'message' => 'Invalid params',
                    'data' => $e->getMessage()
                )),
                $this->payload
            );
        }
        catch (Exception $e){
			return $this->getResponse(array(
                'error' => array(
                    'code' => -32000,
                    'message' => 'Server error',
					'data' => $e->getMessage()
                )),
                $this->payload
            );
		}
    }

    /**
     * Execute the procedure
     *
     * @access public
     * @param  string   $procedure    Procedure name
     * @param  array    $params       Procedure params
     * @return mixed
     */
    public function executeProcedure($procedure, array $params = array())
    {
        if (isset($this->callbacks[$procedure])) {
            return $this->executeCallback($this->callbacks[$procedure], $params);
        }
        else if (isset($this->classes[$procedure])) {
            return $this->executeMethod($this->classes[$procedure][0], $this->classes[$procedure][1], $params);
        }

        foreach ($this->instances as $instance) {
            if (method_exists($instance, $procedure)) {
                return $this->executeMethod($instance, $procedure, $params);
            }
        }

        throw new BadFunctionCallException('Unable to find the procedure');
    }

    /**
     * Execute a callback
     *
     * @access public
     * @param  Closure   $callback     Callback
     * @param  array     $params       Procedure params
     * @return mixed
     */
    public function executeCallback(Closure $callback, $params)
    {
        $reflection = new ReflectionFunction($callback);

        $arguments = $this->getArguments(
            $params,
            $reflection->getParameters(),
            $reflection->getNumberOfRequiredParameters(),
            $reflection->getNumberOfParameters()
        );

        return $reflection->invokeArgs($arguments);
    }

    /**
     * Execute a method
     *
     * @access public
     * @param  mixed     $class        Class name or instance
     * @param  string    $method       Method name
     * @param  array     $params       Procedure params
     * @return mixed
     */
    public function executeMethod($class, $method, $params)
    {
        $reflection = new ReflectionMethod($class, $method);

        $arguments = $this->getArguments(
            $params,
            $reflection->getParameters(),
            $reflection->getNumberOfRequiredParameters(),
            $reflection->getNumberOfParameters()
        );

        return $reflection->invokeArgs(
            is_string($class) ? new $class : $class,
            $arguments
        );
    }

    /**
     * Get procedure arguments
     *
     * @access public
     * @param  array    $request_params       Incoming arguments
     * @param  array    $method_params        Procedure arguments
     * @param  integer  $nb_required_params   Number of required parameters
     * @param  integer  $nb_max_params        Maximum number of parameters
     * @return array
     */
    public function getArguments(array $request_params, array $method_params, $nb_required_params, $nb_max_params)
    {
        $nb_params = count($request_params);

        if ($nb_params < $nb_required_params) {
            throw new InvalidArgumentException('Wrong number of arguments');
        }

        if ($nb_params > $nb_max_params) {
            throw new InvalidArgumentException('Too many arguments');
        }

        if ($this->isPositionalArguments($request_params, $method_params)) {
            return $request_params;
        }

        return $this->getNamedArguments($request_params, $method_params);
    }

    /**
     * Return true if we have positional parametes
     *
     * @access public
     * @param  array    $request_params      Incoming arguments
     * @param  array    $method_params       Procedure arguments
     * @return bool
     */
    public function isPositionalArguments(array $request_params, array $method_params)
    {
        return array_keys($request_params) === range(0, count($request_params) - 1);
    }

    /**
     * Get named arguments
     *
     * @access public
     * @param  array    $request_params      Incoming arguments
     * @param  array    $method_params       Procedure arguments
     * @return array
     */
    public function getNamedArguments(array $request_params, array $method_params)
    {
        $params = array();

        foreach ($method_params as $p) {

            $name = $p->getName();

            if (isset($request_params[$name])) {
                $params[$name] = $request_params[$name];
            }
            else if ($p->isDefaultValueAvailable()) {
                $params[$name] = $p->getDefaultValue();
            }
            else {
                throw new InvalidArgumentException('Missing argument: '.$name);
            }
        }

        return $params;
    }
}
