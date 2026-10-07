import sqlite3

db = sqlite3.connect("scitbd_ceo.db")
db.row_factory = sqlite3.Row

print("SEO score distribution:")
q = ("select content_type,platform,round(avg(seo_score)) avg,"
     "min(seo_score) mn,max(seo_score) mx,count(*) n "
     "from content_items group by content_type,platform")
for r in db.execute(q):
    print("  {:<8}{:<9} avg={:>3} min={:>3} max={:>3} n={}".format(
        r["content_type"], r["platform"], r["avg"], r["mn"], r["mx"], r["n"]))

print("\ntitle lengths vs score (social):")
q = ("select platform,seq,title,length(title) tl,seo_score "
     "from content_items where content_type='social' "
     "order by platform,seq")
for r in db.execute(q):
    print("  {:<9} len={:>2} score={:>3} | {}".format(
        r["platform"], r["tl"], r["seo_score"], r["title"]))

print("\nblog titles (meta len / score):")
q = ("select seq,title,length(meta_description) ml,seo_score "
     "from content_items where content_type='blog' order by seq")
for r in db.execute(q):
    t = r["title"][:48]
    print("  meta={:>3} score={:>3} | {}".format(r["ml"], r["seo_score"], t))

print("\nemail (preview len / score):")
q = ("select seq,title,length(meta_description) ml,seo_score "
     "from content_items where content_type='email' order by seq")
for r in db.execute(q):
    t = r["title"][:48]
    print("  prev={:>3} score={:>3} | {}".format(r["ml"], r["seo_score"], t))

print("\noverall avg:", db.execute(
    "select round(avg(seo_score),1) from content_items").fetchone()[0])
db.close()
