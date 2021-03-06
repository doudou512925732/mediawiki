<?php
/**
 * Generator and manager of database load balancing objects
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Database
 */

/**
 * An interface for generating database load balancers
 * @ingroup Database
 * @since 1.28
 */
interface ILBFactory {
	const SHUTDOWN_NO_CHRONPROT = 0; // don't save DB positions at all
	const SHUTDOWN_CHRONPROT_ASYNC = 1; // save DB positions, but don't wait on remote DCs
	const SHUTDOWN_CHRONPROT_SYNC = 2; // save DB positions, waiting on all DCs

	/**
	 * Construct a manager of ILoadBalancer objects
	 *
	 * Sub-classes will extend the required keys in $conf with additional parameters
	 *
	 * @param $conf $params Array with keys:
	 *  - localDomain: A DatabaseDomain or domain ID string.
	 *  - readOnlyReason : Reason the master DB is read-only if so [optional]
	 *  - srvCache : BagOStuff object for server cache [optional]
	 *  - memCache : BagOStuff object for cluster memory cache [optional]
	 *  - wanCache : WANObjectCache object [optional]
	 *  - hostname : The name of the current server [optional]
	 *  - cliMode: Whether the execution context is a CLI script. [optional]
	 *  - profiler : Class name or instance with profileIn()/profileOut() methods. [optional]
	 *  - trxProfiler: TransactionProfiler instance. [optional]
	 *  - replLogger: PSR-3 logger instance. [optional]
	 *  - connLogger: PSR-3 logger instance. [optional]
	 *  - queryLogger: PSR-3 logger instance. [optional]
	 *  - perfLogger: PSR-3 logger instance. [optional]
	 *  - errorLogger : Callback that takes an Exception and logs it. [optional]
	 * @throws InvalidArgumentException
	 */
	public function __construct( array $conf );

	/**
	 * Disables all load balancers. All connections are closed, and any attempt to
	 * open a new connection will result in a DBAccessError.
	 * @see ILoadBalancer::disable()
	 */
	public function destroy();

	/**
	 * Create a new load balancer object. The resulting object will be untracked,
	 * not chronology-protected, and the caller is responsible for cleaning it up.
	 *
	 * This method is for only advanced usage and callers should almost always use
	 * getMainLB() instead. This method can be useful when a table is used as a key/value
	 * store. In that cases, one might want to query it in autocommit mode (DBO_TRX off)
	 * but still use DBO_TRX transaction rounds on other tables.
	 *
	 * @param bool|string $domain Domain ID, or false for the current domain
	 * @return ILoadBalancer
	 */
	public function newMainLB( $domain = false );

	/**
	 * Get a cached (tracked) load balancer object.
	 *
	 * @param bool|string $domain Domain ID, or false for the current domain
	 * @return ILoadBalancer
	 */
	public function getMainLB( $domain = false );

	/**
	 * Create a new load balancer for external storage. The resulting object will be
	 * untracked, not chronology-protected, and the caller is responsible for
	 * cleaning it up.
	 *
	 * This method is for only advanced usage and callers should almost always use
	 * getExternalLB() instead. This method can be useful when a table is used as a
	 * key/value store. In that cases, one might want to query it in autocommit mode
	 * (DBO_TRX off) but still use DBO_TRX transaction rounds on other tables.
	 *
	 * @param string $cluster External storage cluster, or false for core
	 * @param bool|string $domain Domain ID, or false for the current domain
	 * @return ILoadBalancer
	 */
	public function newExternalLB( $cluster, $domain = false );

	/**
	 * Get a cached (tracked) load balancer for external storage
	 *
	 * @param string $cluster External storage cluster, or false for core
	 * @param bool|string $domain Domain ID, or false for the current domain
	 * @return ILoadBalancer
	 */
	public function getExternalLB( $cluster, $domain = false );

	/**
	 * Execute a function for each tracked load balancer
	 * The callback is called with the load balancer as the first parameter,
	 * and $params passed as the subsequent parameters.
	 *
	 * @param callable $callback
	 * @param array $params
	 */
	public function forEachLB( $callback, array $params = [] );

	/**
	 * Prepare all tracked load balancers for shutdown
	 * @param integer $mode One of the class SHUTDOWN_* constants
	 * @param callable|null $workCallback Work to mask ChronologyProtector writes
	 */
	public function shutdown(
		$mode = self::SHUTDOWN_CHRONPROT_SYNC, callable $workCallback = null
	);

	/**
	 * Commit all replica DB transactions so as to flush any REPEATABLE-READ or SSI snapshot
	 *
	 * @param string $fname Caller name
	 */
	public function flushReplicaSnapshots( $fname = __METHOD__ );

	/**
	 * Commit on all connections. Done for two reasons:
	 * 1. To commit changes to the masters.
	 * 2. To release the snapshot on all connections, master and replica DB.
	 * @param string $fname Caller name
	 * @param array $options Options map:
	 *   - maxWriteDuration: abort if more than this much time was spent in write queries
	 */
	public function commitAll( $fname = __METHOD__, array $options = [] );

	/**
	 * Flush any master transaction snapshots and set DBO_TRX (if DBO_DEFAULT is set)
	 *
	 * The DBO_TRX setting will be reverted to the default in each of these methods:
	 *   - commitMasterChanges()
	 *   - rollbackMasterChanges()
	 *   - commitAll()
	 *
	 * This allows for custom transaction rounds from any outer transaction scope.
	 *
	 * @param string $fname
	 * @throws DBTransactionError
	 */
	public function beginMasterChanges( $fname = __METHOD__ );

	/**
	 * Commit changes on all master connections
	 * @param string $fname Caller name
	 * @param array $options Options map:
	 *   - maxWriteDuration: abort if more than this much time was spent in write queries
	 * @throws Exception
	 */
	public function commitMasterChanges( $fname = __METHOD__, array $options = [] );

	/**
	 * Rollback changes on all master connections
	 * @param string $fname Caller name
	 */
	public function rollbackMasterChanges( $fname = __METHOD__ );

	/**
	 * Determine if any master connection has pending changes
	 * @return bool
	 */
	public function hasMasterChanges();

	/**
	 * Detemine if any lagged replica DB connection was used
	 * @return bool
	 */
	public function laggedReplicaUsed();

	/**
	 * Determine if any master connection has pending/written changes from this request
	 * @param float $age How many seconds ago is "recent" [defaults to LB lag wait timeout]
	 * @return bool
	 */
	public function hasOrMadeRecentMasterChanges( $age = null );

	/**
	 * Waits for the replica DBs to catch up to the current master position
	 *
	 * Use this when updating very large numbers of rows, as in maintenance scripts,
	 * to avoid causing too much lag. Of course, this is a no-op if there are no replica DBs.
	 *
	 * By default this waits on all DB clusters actually used in this request.
	 * This makes sense when lag being waiting on is caused by the code that does this check.
	 * In that case, setting "ifWritesSince" can avoid the overhead of waiting for clusters
	 * that were not changed since the last wait check. To forcefully wait on a specific cluster
	 * for a given domain, use the 'domain' parameter. To forcefully wait on an "external" cluster,
	 * use the "cluster" parameter.
	 *
	 * Never call this function after a large DB write that is *still* in a transaction.
	 * It only makes sense to call this after the possible lag inducing changes were committed.
	 *
	 * @param array $opts Optional fields that include:
	 *   - domain : wait on the load balancer DBs that handles the given domain ID
	 *   - cluster : wait on the given external load balancer DBs
	 *   - timeout : Max wait time. Default: ~60 seconds
	 *   - ifWritesSince: Only wait if writes were done since this UNIX timestamp
	 * @throws DBReplicationWaitError If a timeout or error occured waiting on a DB cluster
	 */
	public function waitForReplication( array $opts = [] );

	/**
	 * Add a callback to be run in every call to waitForReplication() before waiting
	 *
	 * Callbacks must clear any transactions that they start
	 *
	 * @param string $name Callback name
	 * @param callable|null $callback Use null to unset a callback
	 */
	public function setWaitForReplicationListener( $name, callable $callback = null );

	/**
	 * Get a token asserting that no transaction writes are active
	 *
	 * @param string $fname Caller name (e.g. __METHOD__)
	 * @return mixed A value to pass to commitAndWaitForReplication()
	 */
	public function getEmptyTransactionTicket( $fname );

	/**
	 * Convenience method for safely running commitMasterChanges()/waitForReplication()
	 *
	 * This will commit and wait unless $ticket indicates it is unsafe to do so
	 *
	 * @param string $fname Caller name (e.g. __METHOD__)
	 * @param mixed $ticket Result of getEmptyTransactionTicket()
	 * @param array $opts Options to waitForReplication()
	 * @throws DBReplicationWaitError
	 */
	public function commitAndWaitForReplication( $fname, $ticket, array $opts = [] );

	/**
	 * @param string $dbName DB master name (e.g. "db1052")
	 * @return float|bool UNIX timestamp when client last touched the DB or false if not recent
	 */
	public function getChronologyProtectorTouched( $dbName );

	/**
	 * Disable the ChronologyProtector for all load balancers
	 *
	 * This can be called at the start of special API entry points
	 */
	public function disableChronologyProtection();

	/**
	 * Set a new table prefix for the existing local domain ID for testing
	 *
	 * @param string $prefix
	 */
	public function setDomainPrefix( $prefix );

	/**
	 * Close all open database connections on all open load balancers.
	 */
	public function closeAll();

	/**
	 * @param string $agent Agent name for query profiling
	 */
	public function setAgentName( $agent );

	/**
	 * Append ?cpPosTime parameter to a URL for ChronologyProtector purposes if needed
	 *
	 * Note that unlike cookies, this works accross domains
	 *
	 * @param string $url
	 * @param float $time UNIX timestamp just before shutdown() was called
	 * @return string
	 */
	public function appendPreShutdownTimeAsQuery( $url, $time );

	/**
	 * @param array $info Map of fields, including:
	 *   - IPAddress : IP address
	 *   - UserAgent : User-Agent HTTP header
	 *   - ChronologyProtection : cookie/header value specifying ChronologyProtector usage
	 */
	public function setRequestInfo( array $info );
}
