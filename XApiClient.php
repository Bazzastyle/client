<?php

/*
 * This file is part of the xAPI package.
 *
 * (c) Christian Flothmann <christian.flothmann@xabbuh.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Xabbuh\XApi\Client;

use Guzzle\Http\ClientInterface;
use Guzzle\Http\Exception\ClientErrorResponseException;
use Guzzle\Http\Message\RequestInterface;
use JMS\Serializer\SerializerInterface;
use Xabbuh\XApi\Common\Exception\AccessDeniedException;
use Xabbuh\XApi\Common\Exception\ConflictException;
use Xabbuh\XApi\Common\Exception\NotFoundException;
use Xabbuh\XApi\Common\Exception\XApiException;
use Xabbuh\XApi\Common\Model\ActivityProfileDocumentInterface;
use Xabbuh\XApi\Common\Model\ActivityProfileInterface;
use Xabbuh\XApi\Common\Model\ActorInterface;
use Xabbuh\XApi\Common\Model\AgentProfileDocumentInterface;
use Xabbuh\XApi\Common\Model\AgentProfileInterface;
use Xabbuh\XApi\Common\Model\DocumentInterface;
use Xabbuh\XApi\Common\Model\StateDocumentInterface;
use Xabbuh\XApi\Common\Model\StateInterface;
use Xabbuh\XApi\Common\Model\StatementInterface;
use Xabbuh\XApi\Common\Model\StatementResultInterface;

/**
 * An Experience API client.
 *
 * @author Christian Flothmann <christian.flothmann@xabbuh.de>
 */
class XApiClient implements XApiClientInterface
{
    private $httpClient;

    private $serializer;

    private $version;

    private $username;

    private $password;

    /**
     * @param ClientInterface     $httpClient The HTTP client
     * @param SerializerInterface $serializer The serializer
     * @param string              $version    The xAPI version
     */
    public function __construct(ClientInterface $httpClient, SerializerInterface $serializer, $version)
    {
        $this->httpClient = $httpClient;
        $this->serializer = $serializer;
        $this->version = $version;
    }

    /**
     * Returns the HTTP client used to perform the API requests.
     *
     * @return ClientInterface The HTTP client
     */
    public function getHttpClient()
    {
        return $this->httpClient;
    }

    /**
     * Returns the serializer.
     *
     * @return SerializerInterface The serializer
     */
    public function getSerializer()
    {
        return $this->serializer;
    }

    /**
     * Sets HTTP auth credentials.
     *
     * @param string $username The username
     * @param string $password The password
     */
    public function setAuth($username, $password)
    {
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * Returns the xAPI version.
     *
     * @return string The xAPI version
     */
    public function getVersion()
    {
        return $this->version;
    }

    /**
     * Returns the HTTP auth username.
     *
     * @return string The username
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * Returns the HTTP auth password.
     *
     * @return string The password
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * {@inheritDoc}
     */
    public function storeStatement(StatementInterface $statement)
    {
        if (null !== $statement->getId()) {
            return $this->doStoreStatements(
                $statement,
                'put',
                array('statementId' => $statement->getId()),
                204
            );
        } else {
            return $this->doStoreStatements($statement);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function storeStatements(array $statements)
    {
        // check that only Statements without ids will be sent to the LRS
        foreach ($statements as $statement) {
            $isStatement = is_object($statement) && $statement instanceof StatementInterface;

            if (!$isStatement || null !== $statement->getId()) {
                throw new \InvalidArgumentException('API can only handle statements without ids');
            }
        }

        return $this->doStoreStatements($statements);
    }

    /**
     * {@inheritDoc}
     */
    public function voidStatement(StatementInterface $statement, ActorInterface $actor)
    {
        return $this->storeStatement($statement->getVoidStatement($actor));
    }

    /**
     * {@inheritDoc}
     */
    public function getStatement($statementId)
    {
        return $this->doGetStatements('statements', array('statementId' => $statementId));
    }

    /**
     * {@inheritDoc}
     */
    public function getVoidedStatement($statementId)
    {
        return $this->doGetStatements('statements', array('voidedStatementId' => $statementId));
    }

    /**
     * {@inheritDoc}
     */
    public function getStatements(StatementsFilterInterface $filter = null)
    {
        $urlParameters = array();

        if (null !== $filter) {
            $urlParameters = $filter->getFilter();
        }

        // the Agent must be JSON encoded
        if (isset($urlParameters['agent'])) {
            $urlParameters['agent'] = $this->serializer->serialize(
                $urlParameters['agent'],
                'json'
            );
        }

        return $this->doGetStatements('statements', $urlParameters);
    }

    /**
     * {@inheritDoc}
     */
    public function getNextStatements(StatementResultInterface $statementResult)
    {
        return $this->doGetStatements($statementResult->getMoreUrlPath());
    }

    /**
     * {@inheritDoc}
     */
    public function createOrUpdateStateDocument(StateDocumentInterface $document)
    {
        $this->doStoreStateDocument('post', $document);
    }

    /**
     * {@inheritDoc}
     */
    public function createOrReplaceStateDocument(StateDocumentInterface $document)
    {
        $this->doStoreStateDocument('put', $document);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteStateDocument(StateInterface $state)
    {
        $this->doDeleteDocument('activities/state', array(
            'activityId' => $state->getActivity()->getId(),
            'agent' => $this->serializer->serialize($state->getActor(), 'json'),
            'stateId' => $state->getStateId(),
        ));
    }

    /**
     * {@inheritDoc}
     */
    public function getStateDocument(StateInterface $state)
    {
        /** @var \Xabbuh\XApi\Common\Model\StateDocument $document */
        $document = $this->doGetDocument('Xabbuh\XApi\Common\Model\StateDocument', 'activities/state', array(
            'activityId' => $state->getActivity()->getId(),
            'agent' => $this->serializer->serialize($state->getActor(), 'json'),
            'stateId' => $state->getStateId(),
        ));
        $document->setState($state);

        return $document;
    }

    /**
     * {@inheritDoc}
     */
    public function createOrUpdateActivityProfileDocument(ActivityProfileDocumentInterface $document)
    {
        $this->doStoreActivityProfileDocument('post', $document);
    }

    /**
     * {@inheritDoc}
     */
    public function createOrReplaceActivityProfileDocument(ActivityProfileDocumentInterface $document)
    {
        $this->doStoreActivityProfileDocument('put', $document);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteActivityProfileDocument(ActivityProfileInterface $profile)
    {
        $this->doDeleteDocument('activities/profile', array(
            'activityId' => $profile->getActivity()->getId(),
            'profileId' => $profile->getProfileId(),
        ));
    }

    /**
     * {@inheritDoc}
     */
    public function getActivityProfileDocument(ActivityProfileInterface $profile)
    {
        /** @var \Xabbuh\XApi\Common\Model\ActivityProfileDocument $document */
        $document = $this->doGetDocument(
            'Xabbuh\XApi\Common\Model\ActivityProfileDocument',
            'activities/profile',
            array(
                'activityId' => $profile->getActivity()->getId(),
                'profileId' => $profile->getProfileId(),
            )
        );
        $document->setActivityProfile($profile);

        return $document;
    }

    /**
     * {@inheritDoc}
     */
    public function createOrUpdateAgentProfileDocument(AgentProfileDocumentInterface $document)
    {
        $profile = $document->getAgentProfile();
        $this->doStoreDocument('post', 'agents/profile', array(
            'agent' => $this->serializer->serialize($profile->getAgent(), 'json'),
            'profileId' => $profile->getProfileId(),
        ), $document);
    }

    /**
     * {@inheritDoc}
     */
    public function createOrReplaceAgentProfileDocument(AgentProfileDocumentInterface $document)
    {
        $profile = $document->getAgentProfile();
        $this->doStoreDocument('put', 'agents/profile', array(
            'agent' => $this->serializer->serialize($profile->getAgent(), 'json'),
            'profileId' => $profile->getProfileId(),
        ), $document);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteAgentProfileDocument(AgentProfileInterface $profile)
    {
        $this->doDeleteDocument('agents/profile', array(
            'agent' => $this->serializer->serialize($profile->getAgent(), 'json'),
            'profileId' => $profile->getProfileId(),
        ));
    }

    /**
     * {@inheritDoc}
     */
    public function getAgentProfileDocument(AgentProfileInterface $profile)
    {
        /** @var \Xabbuh\XApi\Common\Model\AgentProfileDocument $document */
        $document = $this->doGetDocument(
            'Xabbuh\XApi\Common\Model\AgentProfileDocument',
            'agents/profile',
            array(
                'agent' => $this->serializer->serialize($profile->getAgent(), 'json'),
                'profileId' => $profile->getProfileId(),
            )
        );
        $document->setAgentProfile($profile);

        return $document;
    }

    /**
     * @param StatementInterface|StatementInterface[] $statements
     * @param string                                  $method
     * @param string[]                                $parameters
     * @param int                                     $validStatusCode
     *
     * @return StatementInterface|StatementInterface[] The created statement(s)
     */
    private function doStoreStatements($statements, $method = 'post', $parameters = array(), $validStatusCode = 200)
    {
        $request = $this->createRequest(
            $method,
            'statements',
            $parameters,
            $this->serializer->serialize($statements, 'json')
        );

        $response = $this->performRequest($request, array($validStatusCode));
        $statementIds = json_decode($response->getBody(true));

        if (is_array($statements)) {
            $createdStatements = array();

            foreach ($statementIds as $index => $statementId) {
                /** @var StatementInterface $statement */
                $statement = clone $statements[$index];
                $statement->setId($statementId);
                $createdStatements[] = $statement;
            }

            return $createdStatements;
        } else {
            $createdStatement = clone $statements;
            $createdStatement->setId($statementIds[0]);

            return $createdStatement;
        }
    }

    /**
     * Fetch one or more Statements.
     *
     * @param string $url           URL to request
     * @param array  $urlParameters URL parameters
     *
     * @return StatementInterface|\Xabbuh\XApi\Common\Model\StatementResultInterface
     */
    private function doGetStatements($url, array $urlParameters = array())
    {
        $request = $this->createRequest('get', $url, $urlParameters);
        $response = $this->performRequest($request, array(200));

        if (isset($urlParameters['statementId']) || isset($urlParameters['voidedStatementId'])) {
            $class = 'Xabbuh\XApi\Common\Model\Statement';
        } else {
            $class = 'Xabbuh\XApi\Common\Model\StatementResult';
        }

        return $this->serializer->deserialize(
            $response->getBody(true),
            $class,
            'json'
        );
    }

    /**
     * Stores a document.
     *
     * @param string            $method        HTTP method to use
     * @param string            $uri           Endpoint URI
     * @param array             $urlParameters URL parameters
     * @param DocumentInterface $document      The document to store
     */
    private function doStoreDocument($method, $uri, $urlParameters, DocumentInterface $document)
    {
        $request = $this->createRequest(
            $method,
            $uri,
            $urlParameters,
            $this->serializer->serialize($document, 'json')
        );
        $this->performRequest($request, array(204));
    }

    /**
     * Stores a state document.
     *
     * @param string                 $method   HTTP method to use
     * @param StateDocumentInterface $document The document to store
     */
    private function doStoreStateDocument($method, StateDocumentInterface $document)
    {
        $state = $document->getState();
        $this->doStoreDocument(
            $method,
            'activities/state',
            array(
                'activityId' => $state->getActivity()->getId(),
                'agent' => $this->serializer->serialize($state->getActor(), 'json'),
                'stateId' => $state->getStateId(),
            ),
            $document
        );
    }

    /**
     * Stores a state document.
     *
     * @param string                           $method   HTTP method to use
     * @param ActivityProfileDocumentInterface $document The document to store
     */
    private function doStoreActivityProfileDocument($method, ActivityProfileDocumentInterface $document)
    {
        $profile = $document->getActivityProfile();
        $this->doStoreDocument(
            $method,
            'activities/profile',
            array(
                'activityId' => $profile->getActivity()->getId(),
                'profileId' => $profile->getProfileId(),
            ),
            $document
        );
    }

    /**
     * Deletes a document.
     *
     * @param string $uri           The endpoint URI
     * @param array  $urlParameters The URL parameters
     */
    private function doDeleteDocument($uri, array $urlParameters)
    {
        $request = $this->createRequest('delete', $uri, $urlParameters);
        $this->performRequest($request, array(204));
    }

    /**
     * Returns a document.
     *
     * @param string $type          The document type
     * @param string $uri           The endpoint URI
     * @param array  $urlParameters The URL parameters
     *
     * @return \Xabbuh\XApi\Common\Model\DocumentInterface The document
     */
    private function doGetDocument($type, $uri, array $urlParameters)
    {
        $request = $this->createRequest('get', $uri, $urlParameters);
        $response = $this->performRequest($request, array(200));
        $document = $this->serializer->deserialize($response->getBody(true), $type, 'json');

        return $document;
    }

    /**
     * @param string $method        The HTTP method
     * @param string $uri           The URI to send the request to
     * @param array  $urlParameters Optional url parameters
     * @param string $body          An optional request body
     *
     * @return RequestInterface The request
     *
     * @throws \InvalidArgumentException when no valid HTTP method is given
     */
    private function createRequest($method, $uri, array $urlParameters = array(), $body = null)
    {
        if (count($urlParameters) > 0) {
            $uri .= '?'.http_build_query($urlParameters);
        }

        switch ($method) {
            case 'get':
                $request = $this->httpClient->get($uri);
                break;
            case 'post':
                $request = $this->httpClient->post($uri, null, $body);
                break;
            case 'put':
                $request = $this->httpClient->put($uri, null, $body);
                break;
            case 'delete':
                $request = $this->httpClient->delete($uri);
                break;
            default:
                throw new \InvalidArgumentException(
                    $method.' is no valid HTTP method'
                );
        }

        $request->addHeader('X-Experience-API-Version', $this->version);
        $request->addHeader('Content-Type', 'application/json');
        $request->setAuth($this->username, $this->password);

        return $request;
    }

    /**
     * Performs the given HTTP request.
     *
     * @param RequestInterface $request          The HTTP request to perform
     * @param int[]            $validStatusCodes A list of HTTP status codes
     *                                           the calling method is able to
     *                                           handle
     *
     * @return \Guzzle\Http\Message\Response The remote server's response
     *
     * @throws XApiException when the request fails
     */
    private function performRequest(RequestInterface $request, array $validStatusCodes)
    {
        try {
            $response = $request->send();
        } catch (ClientErrorResponseException $e) {
            $response = $e->getResponse();
        }

        // catch some common errors
        if (in_array($response->getStatusCode(), array(401, 403))) {
            throw new AccessDeniedException(
                $response->getBody(true),
                $response->getStatusCode()
            );
        } elseif (404 === $response->getStatusCode()) {
            throw new NotFoundException($response->getBody(true));
        } elseif (409 === $response->getStatusCode()) {
            throw new ConflictException($response->getBody(true));
        }

        if (!in_array($response->getStatusCode(), $validStatusCodes)) {
            throw new XApiException($response->getBody(true), $response->getStatusCode());
        }

        return $response;
    }
}
