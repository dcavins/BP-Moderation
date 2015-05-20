<?php

/**
 * Abstract DB Object
 *
 * This abstract class can be extended to create a class for get/insert/update/delete
 * an object in a db table with a few lines of code
 *
 * this proprierties have to be defined in child class:
 * table,obj_name,fields_format
 *
 * @author Francesco
 */
class bpModAbstractDBobj
{

	/**
	 * database table name
	 *
	 * @var string
	 */
	var $__table;

	/**
	 * name of the object (used for calling actions and filters)
	 *
	 * @var string
	 */
	var $__obj_name;

	/**
	 * primary key column name
	 *
	 * @var string
	 */
	var $__id_field;

	/**
	 * associative array that relates columns name to the corresponding format
	 *
	 * 'column_name' => '%s' or '%d'
	 *
	 * primary key is considered %d by default
	 * if a format is not defined %s is used
	 *
	 * @var array
	 */
	var $__fields_format = array();

	/**
	 * stores value of primary key
	 *
	 * @var int
	 */
	var $__id;

	/**
	 * associative arrays that stores values of columns other than primary key
	 *
	 * 'column_name' => $value
	 *
	 * @var array
	 */
	var $__data = array();

	function  __construct($id = false)
	{
		if ((int)$id) {
			$this->populate($id);
		}
	}

	/**
	 * populate()
	 *
	 * This method will populate the object with a row from the database, based on the
	 * ID passed to the constructor.
	 */
	function populate($id)
	{
		global $wpdb;

		$sql = $wpdb->prepare("SELECT * FROM {$this->__table} WHERE {$this->__id_field} = %d", (int)$id);
		$row = $wpdb->get_row($sql, ARRAY_A);
		if ($row && !empty($row[$this->__id_field])) {
			$this->__id = $row[$this->__id_field];
			unset($row[$this->__id_field]);
			$this->__data = $row;
		}
	}

	/**
	 * save()
	 *
	 * This method will save an object to the database. It will dynamically switch between
	 * INSERT and UPDATE depending on whether or not the object already exists in the database.
	 */
	function save()
	{
		global $wpdb;

		$formats = array();

		//note: $value can't be referenced in php4
		foreach ($this->__data as $field => $value) {

			$this->__data[$field] = apply_filters($this->__obj_name . '_data_' . $field . '_before_save', $value, $this->__id);

			if (!empty($this->__fields_format[$field])) {
				$formats[] = $this->__fields_format[$field];
			}
			else
			{
				$formats[] = '%s';
			}
		}

		/* Call a before save action here */
		do_action($this->__obj_name . '_data_before_save', array(&$this));

		if ($this->__id) {
			// Update
			$result = $wpdb->update($this->__table, $this->__data, array($this->__id_field => $this->__id), $formats, array('%d'));
		} else {
			// Save
			$result = $wpdb->insert($this->__table, $this->__data, $formats);
		}

		if (!$result) {
			return false;
		}

		if (!$this->__id) {
			$this->__id = $wpdb->insert_id;
		}

		/* Add an after save action here */
		do_action($this->__obj_name . '_data_after_save', array(&$this));

		return $result;
	}

	/**
	 * delete()
	 *
	 * This method will delete the corresponding row for an object from the database.
	 *
	 * @return bool if the row has been deleted
	 */
	function delete()
	{
		global $wpdb;

		return $wpdb->query($wpdb->prepare("DELETE FROM {$this->__table} WHERE {$this->__id_field} = %d", $this->__id));
	}

	/**
	 * get()
	 *
	 * This method will return results based on search criteria.
	 *
	 * @since 0.2.0
	 *
	 * @param array $r {
	 *     Array of arguments.
	 *     @type array $select Column names as array values to select. Also supports '*' as value.
	 *                         eg. array( 'content_id', 'item_type' )
	 *     @type array $where  Column names as array key, array values as search term. Supports only
	 *                         EQUALS comparisons at the moment.
	 *                         eg. array( 'item_id' => 123, 'item_id2' => 456 ) equates to:
	 *                             'item_id = 123 AND item_id2 = 456'
	 * }
	 * @return mixed
	 */
	public function get( $r = array() ) {
		global $wpdb;

		$r = array_merge( array(
			'select' => array( '*' ),
			'where'  => array(),
		), $r );

		// SELECT
		$select_sql = '';
		if ( ! empty( $r['select'] ) ) {
			// validate SELECT columns
			$r['select'] = array_values(
				array_intersect(
					array_merge( array_keys( $this->__fields_format ),
					array( '*', $this->__id_field )
				),
				$r['select']
			) );

			if ( ! empty( $r['select'] ) ) {
				$select_sql = 'SELECT ' . join( ',', $r['select'] );
			} else {
				$r['select'] = array( '*' );
			}

		}
		if ( empty( $select_sql ) ) {
			$select_sql = "SELECT *";
		}

		// FROM
		$from_sql = "FROM {$this->__table}";

		// WHERE
		$where_sql = '';
		$where = array();
		if ( ! empty( $r['where'] ) ) {
			// Validate WHERE columns
			foreach ( (array) $r['where'] as $k => $v ) {
				if ( isset( $this->__fields_format[$k] ) ) {
					$where[] = $wpdb->prepare( "{$k} = " . $this->__fields_format[$k], $r['where'][$k] );
				}
			}
		}
		if ( ! empty( $where ) ) {
			$where_sql = 'WHERE ' . join( ' AND ', $where );
		}

		if ( array( '*' ) === $r['select'] || count( $r['select'] ) > 1 ) {
			return $wpdb->get_results( "{$select_sql} {$from_sql} {$where_sql}" );
		} else {
			return $wpdb->get_var( "{$select_sql} {$from_sql} {$where_sql}" );
		}
	}

	/**
	 * give a more covenient way of storing fields value without have to remember
	 * where they are stored
	 *
	 * @param string $field | field to store in
	 * @param mixed $vaule | value to store
	 */
	function __set($field, $value)
	{
		if ($field == $this->__id_field) {
			$this->__id = $value;
		}
		else
		{
			$this->__data[$field] = $value;
		}
	}

	/**
	 * give a more covenient way of getting fields value without have to remember
	 * where they are stored
	 *
	 * @param string $field | field to get
	 * @return mixed $value
	 */
	function __get($field)
	{
		if ($field == $this->__id_field) {
			return $this->__id;
		}
		elseif (isset ($this->__data[$field]))
		{
			return $this->__data[$field];
		}
		else
		{
			return null;
		}
	}
}

?>
