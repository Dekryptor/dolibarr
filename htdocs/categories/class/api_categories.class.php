<?php
/* Copyright (C) 2015   Jean-François Ferry     <jfefe@aternatik.fr>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

 use Luracast\Restler\RestException;

 require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
 require_once DOL_DOCUMENT_ROOT.'/societe/class/client.class.php';

/**
 * API class for categories
 *
 * @access protected 
 * @class  DolibarrApiAccess {@requires user,external}
 */
class Categories extends DolibarrApi
{
    /**
     * @var array   $FIELDS     Mandatory fields, checked when create and update object 
     */
    static $FIELDS = array(
        'label',
        'type'
    );

    static $TYPES = array(
        0 => 'product',
        1 => 'supplier',
        2 => 'customer',
        3 => 'member',
        4 => 'contact',
        5 => 'account',
    );
    
    /**
     * @var Categorie $category {@type Categorie}
     */
    public $category;

    /**
     * Constructor
     */
    function __construct()
    {
		global $db, $conf;
		$this->db = $db;
        $this->category = new Categorie($this->db);
    }

    /**
     * Get properties of a category object
     *
     * Return an array with category informations
     *
     * @param 	int 	$id ID of category
     * @return 	array|mixed data without useless information
	 * 
     * @throws 	RestException
     */
    function get($id)
    {		
		if(! DolibarrApiAccess::$user->rights->categorie->lire) {
			throw new RestException(401);
		}
			
        $result = $this->category->fetch($id);
        if( ! $result ) {
            throw new RestException(404, 'category not found');
        }
		
		if( ! DolibarrApi::_checkAccessToResource('category',$this->category->id)) {
			throw new RestException(401, 'Access not allowed for login '.DolibarrApiAccess::$user->login);
		}

		return $this->_cleanObjectDatas($this->category);
    }

    /**
     * List categories
     * 
     * Get a list of categories
     *
     * @param string	$sortfield	Sort field
     * @param string	$sortorder	Sort order
     * @param int		$limit		Limit for list
     * @param int		$page		Page number
     * @param string	$type		Type of category ('member', 'customer', 'supplier', 'product', 'contact')
     * @param string    $sqlfilters Other criteria to filter answers separated by a comma. Syntax example "(t.ref:like:'SO-%') and (t.date_creation:<:'20160101')"
     * @return array                Array of category objects
     *
	 * @throws RestException
     */
    function index($sortfield = "t.rowid", $sortorder = 'ASC', $limit = 0, $page = 0, $type = '', $sqlfilters = '') {
        global $db, $conf;
        
        $obj_ret = array();
        
         if(! DolibarrApiAccess::$user->rights->categorie->lire) {
			throw new RestException(401);
		}
        
        $sql = "SELECT t.rowid";
        $sql.= " FROM ".MAIN_DB_PREFIX."categorie as t";
        $sql.= ' WHERE t.entity IN ('.getEntity('category', 1).')';
        if (!empty($type))
        {
            $sql.= ' AND t.type='.array_search($type,Categories::$TYPES);
        }
        // Add sql filters
        if ($sqlfilters) 
        {
            if (! DolibarrApi::_checkFilters($sqlfilters))
            {
                throw new RestException(503, 'Error when validating parameter sqlfilters '.$sqlfilters);
            }
	        $regexstring='\(([^:\'\(\)]+:[^:\'\(\)]+:[^:\(\)]+)\)';
            $sql.=" AND (".preg_replace_callback('/'.$regexstring.'/', 'DolibarrApi::_forge_criteria_callback', $sqlfilters).")";
        }
        
        $sql.= $db->order($sortfield, $sortorder);
        if ($limit)	{
            if ($page < 0)
            {
                $page = 0;
            }
            $offset = $limit * $page;

            $sql.= $db->plimit($limit + 1, $offset);
        }

        $result = $db->query($sql);
        if ($result)
        {
        	$i=0;
            $num = $db->num_rows($result);
            while ($i < min($num, ($limit <= 0 ? $num : $limit)))
            {
                $obj = $db->fetch_object($result);
                $category_static = new Categorie($db);
                if($category_static->fetch($obj->rowid)) {
                    $obj_ret[] = $this->_cleanObjectDatas($category_static);
                }
                $i++;
            }
        }
        else {
            throw new RestException(503, 'Error when retrieve category list : '.$db->lasterror());
        }
        if( ! count($obj_ret)) {
            throw new RestException(404, 'No category found');
        }
		return $obj_ret;
    }

    /**
     * List categories of an entity
     * 
     * Note: This method is not directly exposed in the API, it is used
     * in the GET /xxx/{id}/categories requests.
     *
     * @param string	$sortfield	Sort field
     * @param string	$sortorder	Sort order
     * @param int		$limit		Limit for list
     * @param int		$page		Page number
     * @param string	$type		Type of category ('member', 'customer', 'supplier', 'product', 'contact')
     * @param int		$item		Id of the item to get categories for
     * @return array Array of category objects
     *
     * @access private
     */
    function getListForItem($sortfield = "s.rowid", $sortorder = 'ASC', $limit = 0, $page = 0, $type='customer', $item = 0) {
        global $db, $conf;
        
        $obj_ret = array();
        
         if(! DolibarrApiAccess::$user->rights->categorie->lire) {
			    throw new RestException(401);
         }
        //if ($type == "") {
          //$type="product";
        //}
        $sub_type = $type;
        $subcol_name = "fk_".$type;
        if ($type=="customer" || $type=="supplier") {
          $sub_type="societe";
          $subcol_name="fk_soc";
        }
        if ($type=="contact") {
          $subcol_name="fk_socpeople";
        }
        $sql = "SELECT s.rowid";
        $sql.= " FROM ".MAIN_DB_PREFIX."categorie as s";
        $sql.= " , ".MAIN_DB_PREFIX."categorie_".$sub_type." as sub ";
        $sql.= ' WHERE s.entity IN ('.getEntity('category', 1).')';
        $sql.= ' AND s.type='.array_search($type,Categories::$TYPES);
        $sql.= ' AND s.rowid = sub.fk_categorie';
        $sql.= ' AND sub.'.$subcol_name.' = '.$item;

        $nbtotalofrecords = -1;
        if (empty($conf->global->MAIN_DISABLE_FULL_SCANLIST))
        {
            $result = $db->query($sql);
            $nbtotalofrecords = $db->num_rows($result);
        }

        $sql.= $db->order($sortfield, $sortorder);
        if ($limit)	{
            if ($page < 0)
            {
                $page = 0;
            }
            $offset = $limit * $page;

            $sql.= $db->plimit($limit + 1, $offset);
        }

        $result = $db->query($sql);
        if ($result)
        {
        	$i=0;
            $num = $db->num_rows($result);
            while ($i < min($num, ($limit <= 0 ? $num : $limit)))
            {
                $obj = $db->fetch_object($result);
                $category_static = new Categorie($db);
                if($category_static->fetch($obj->rowid)) {
                    $obj_ret[] = $this->_cleanObjectDatas($category_static);
                }
                $i++;
            }
        }
        else {
            throw new RestException(503, 'Error when retrieve category list : '.$db->lasterror());
        }
        if( ! count($obj_ret)) {
            throw new RestException(404, 'No category found');
        }
		return $obj_ret;
    }

    /**
     * Create category object
     * 
     * @param array $request_data   Request data
     * @return int  ID of category
     */
    function post($request_data = NULL)
    {
        if(! DolibarrApiAccess::$user->rights->categorie->creer) {
			throw new RestException(401);
		}

        // Check mandatory fields
        $result = $this->_validate($request_data);
        
        foreach($request_data as $field => $value) {
            $this->category->$field = $value;
        }
        if($this->category->create(DolibarrApiAccess::$user) < 0) {
            throw new RestException(503, 'Error when create category : '.$this->category->error);
        }
        return $this->category->id;
    }

    /**
     * Update category
     * 
     * @param int   $id             Id of category to update
     * @param array $request_data   Datas   
     * @return int 
     */
    function put($id, $request_data = NULL)
    {
        if(! DolibarrApiAccess::$user->rights->categorie->creer) {
			throw new RestException(401);
		}
        
        $result = $this->category->fetch($id);
        if( ! $result ) {
            throw new RestException(404, 'category not found');
        }
		
		if( ! DolibarrApi::_checkAccessToResource('category',$this->category->id)) {
			throw new RestException(401, 'Access not allowed for login '.DolibarrApiAccess::$user->login);
		}

        foreach($request_data as $field => $value) {
            if ($field == 'id') continue;
            $this->category->$field = $value;
        }
        
        if($this->category->update(DolibarrApiAccess::$user))
            return $this->get ($id);
        
        return false;
    }
    
    /**
     * Delete category
     *
     * @param int $id   Category ID
     * @return array
     */
    function delete($id)
    {
        if(! DolibarrApiAccess::$user->rights->categorie->supprimer) {
			throw new RestException(401);
		}
        $result = $this->category->fetch($id);
        if( ! $result ) {
            throw new RestException(404, 'category not found');
        }
		
		if( ! DolibarrApi::_checkAccessToResource('category',$this->category->id)) {
			throw new RestException(401, 'Access not allowed for login '.DolibarrApiAccess::$user->login);
		}
        
        if (! $this->category->delete(DolibarrApiAccess::$user)) {
            throw new RestException(401,'error when delete category');
        }
        
        return array(
            'success' => array(
                'code' => 200,
                'message' => 'Category deleted'
            )
        );
    }
    
    
    /**
     * Clean sensible object datas
     *
     * @param   Categorie  $object    Object to clean
     * @return    array    Array of cleaned object properties
     */
    function _cleanObjectDatas($object) {
    
        $object = parent::_cleanObjectDatas($object);
    
        // Remove the subscriptions because they are handled as a subresource.
        //unset($object->subscriptions);
    
        return $object;
    }
    
    /**
     * Validate fields before create or update object
     * 
     * @param array|null    $data    Data to validate
     * @return array
     * 
     * @throws RestException
     */
    function _validate($data)
    {
        $category = array();
        foreach (Categories::$FIELDS as $field) {
            if (!isset($data[$field]))
                throw new RestException(400, "$field field missing");
            $category[$field] = $data[$field];
        }
        return $category;
    }
}
