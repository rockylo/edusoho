<?php
namespace Topxia\Service\Announcement\Dao\Impl;

use Topxia\Service\Common\BaseDao;
use Topxia\Service\Announcement\Dao\AnnouncementDao;
use PDO;

class AnnouncementDaoImpl extends BaseDao implements AnnouncementDao
{
    protected $table = 'announcement';

    public function searchAnnouncements($conditions, $orderBy, $start, $limit)
    {
        $this->filterStartLimit($start, $limit);
        $orderBys = $this->filterSort($orderBy);

        $builder = $this->createSearchQueryBuilder($conditions)
            ->select('*')
            ->setFirstResult($start)
            ->setMaxResults($limit);
        foreach ($orderBys as $orderBy) {
            $builder->addOrderBy($orderBy[0], $orderBy[1]);
        }

        return $builder->execute()->fetchAll() ? : array();
    }

    public function getAnnouncement($id)
    {
        $sql = "SELECT * FROM {$this->table} WHERE id = ? LIMIT 1";
        return $this->getConnection()->fetchAssoc($sql, array($id)) ? : null;
    }

	public function addAnnouncement($fields)
	{
        $affected = $this->getConnection()->insert($this->table, $fields);
        if ($affected <= 0) {
            throw $this->createDaoException('Insert announcement error.');
        }
        return $this->getAnnouncement($this->getConnection()->lastInsertId());
	}

	public function deleteAnnouncement($id)
	{
        $sql = "DELETE FROM {$this->table} WHERE id = ? LIMIT 1";
        return $this->getConnection()->executeUpdate($sql, array($id));
	}

	public function updateAnnouncement($id, $fields)
	{
        $id = $this->getConnection()->update($this->table, $fields, array('id' => $id));
        return $this->getAnnouncement($id);
	}

    private function createSearchQueryBuilder($conditions)
    {
        
        $builder = $this->createDynamicQueryBuilder($conditions)
            ->from($this->table, $this->table)
            ->andWhere("targetType = :targetType")
            ->andWhere("targetId = :targetId");
            
        return $builder;
    }

    private function filterSort($sort)
    {
        switch ($sort) {

            case 'createdTime':
                $orderBys = array(
                    array('createdTime', 'DESC'),
                );
                break;

            default:
                throw $this->createDaoException('参数sort不正确。');
        }

        return $orderBys;
    }
}