#!/usr/bin/env python3
"""
Reads the NBB (Central Balance Sheet Office) XBRL taxonomy and writes, for the
sections of the annual accounts this package fills, which concept and which
dimension members each rubric code is reported with, current year and previous
year: src/Resources/data/nbb/datapoints.php.

Run it again when the NBB publishes a new taxonomy:

    python3 bin/nbb-datapoints.py path/to/nbb-cbso-<version>.zip

The taxonomy is the NBB's: https://www.nbb.be (Central Balance Sheet Office,
technical information and taxonomy).
"""
import os
import re
import sys
import tempfile
import zipfile
import xml.etree.ElementTree as ET

X = '{http://www.w3.org/1999/xlink}'
GEN = '{http://xbrl.org/2008/generic}'
FORMULA = '{http://xbrl.org/2008/formula}'
LANG = '{http://www.w3.org/XML/1998/namespace}lang'

# Balance sheet, its liabilities, income statement, appropriation, fixed asset notes:
# the same tables serve the micro and the abridged models of companies with capital.
SECTIONS = ['s.03.01.0.cdefhi', 's.03.02.0.e', 's.04.00.0.ef', 's.05.00.0.cdef', 's.06.01.1.cdefhi', 's.06.01.2.cdef', 's.06.01.3.cdefhi']


def parse(directory, section):
    rend = ET.parse(os.path.join(directory, section + '-rend.xml')).getroot()
    lab = ET.parse(os.path.join(directory, section + '-lab.xml')).getroot()
    nodes, arcs, breakdowns = {}, [], []

    for index, link in enumerate(rend.iter(GEN + 'link')):
        for element in link:
            tag = element.tag.split('}')[1]
            key = (index, element.get(X + 'label'))
            if tag in ('ruleNode', 'breakdown', 'table'):
                aspects = {}
                for dimension in element.iter(FORMULA + 'explicitDimension'):
                    qname = dimension.find('.//' + FORMULA + 'qname')
                    aspects[dimension.get('dimension')] = qname.text if qname is not None else None
                concept = element.find(FORMULA + 'concept/' + FORMULA + 'qname')
                if concept is not None:
                    aspects['concept'] = concept.text
                nodes[key] = {'id': element.get('id'), 'tag': tag, 'aspects': aspects, 'abstract': element.get('abstract') == 'true'}
            elif tag in ('breakdownTreeArc', 'definitionNodeSubtreeArc'):
                arcs.append(((index, element.get(X + 'from')), (index, element.get(X + 'to')), float(element.get('order'))))
            elif tag == 'tableBreakdownArc':
                breakdowns.append(((index, element.get(X + 'to')), element.get('axis'), float(element.get('order'))))

    codes = {}
    for link in lab.iter(GEN + 'link'):
        locators = {el.get(X + 'label'): el.get(X + 'href').split('#')[1] for el in link if el.tag.endswith('}loc')}
        resources = {el.get(X + 'label'): el for el in link if el.tag.endswith('}label')}
        for arc in link:
            if arc.tag.endswith('}arc'):
                node, resource = locators.get(arc.get(X + 'from')), resources.get(arc.get(X + 'to'))
                if node and resource is not None and resource.get(X + 'role').endswith('/rub') and resource.get(LANG) == 'en':
                    codes[node] = resource.text

    children = {}
    for parent, child, order in arcs:
        children.setdefault(parent, []).append((order, child))

    def walk(key, inherited, out):
        node = nodes[key]
        aspects = dict(inherited)
        aspects.update(node['aspects'])
        if node['tag'] == 'ruleNode':
            out.append({'code': codes.get(node['id']), 'abstract': node['abstract'], 'aspects': aspects})
        for _, child in sorted(children.get(key, [])):
            walk(child, aspects if node['tag'] == 'ruleNode' else inherited, out)

    tables = {}
    for breakdown, axis, order in sorted(breakdowns, key=lambda item: item[2]):
        out = []
        walk(breakdown, {}, out)
        tables.setdefault(breakdown[0], {}).setdefault(axis, []).append(out)

    return tables


def datapoints(directory):
    current, previous = {}, {}
    for section in SECTIONS:
        for axes in parse(directory, section).values():
            columns = [column for breakdown in axes.get('x', []) for column in breakdown if not column['abstract']]
            for breakdown in axes.get('y', []):
                for row in breakdown:
                    if row['abstract'] or not row['code']:
                        continue
                    code = row['code'].strip('()')
                    for column in columns:
                        aspects = {**row['aspects'], **column['aspects']}
                        target = current if aspects.get('dim:prd') == 'prd:m1' else previous
                        if aspects.get('concept') and code not in target:
                            target[code] = aspects
    return current, previous


def php(value, indent=0):
    pad = '    ' * indent
    if isinstance(value, dict):
        items = ''.join("%s    '%s' => %s,\n" % (pad, key, php(item, indent + 1)) for key, item in value.items())
        return '[\n' + items + pad + ']'
    return "'" + value.replace("'", "\\'") + "'"


def main(archive):
    with tempfile.TemporaryDirectory() as root:
        zipfile.ZipFile(archive).extractall(root)
        package = open(next(os.path.join(dirpath, name) for dirpath, _, names in os.walk(root) for name in names if name == 'taxonomyPackage.xml')).read()
        version = re.search(r'<version>([^<]+)</version>', package).group(1)
        major = version.rsplit('.', 1)[0]
        sections = next(dirpath for dirpath, _, names in os.walk(root) if dirpath.endswith('/fws/' + major + '/sect'))
        current, previous = datapoints(sections)
        domains = next(dirpath for dirpath, _, names in os.walk(root) if dirpath.endswith('/dict/dom'))
        postal_codes = sorted(set(re.findall(r'id="pcd_m(\d{4})"', open(os.path.join(domains, 'pcd.xsd')).read())))

    for aspects in list(current.values()) + list(previous.values()):
        aspects.pop('dim:prd', None)

    target = os.path.join(os.path.dirname(__file__), '..', 'src', 'Resources', 'data', 'nbb', 'datapoints.php')
    os.makedirs(os.path.dirname(target), exist_ok=True)
    data = {
        'taxonomy': version,
        'entryPoints': {
            'micro': 'http://www.nbb.be/be/fr/cbso/fws/%s/mod/m07/m07-f.xsd' % major,
            'abridged': 'http://www.nbb.be/be/fr/cbso/fws/%s/mod/m01/m01-f.xsd' % major,
        },
        'postalCodes': ','.join(postal_codes),
        'current': current,
        'previous': previous,
    }
    with open(target, 'w') as handle:
        handle.write('<?php\n\n/*\n * Generated by bin/nbb-datapoints.py from the NBB taxonomy %s: the concept and\n * the dimension members of each rubric, current year and previous year.\n * Do not edit: run the script again on a new taxonomy.\n */\nreturn %s;\n' % (version, php(data)))
    print('%s: %d current, %d previous datapoints' % (target, len(current), len(previous)))


if __name__ == '__main__':
    main(sys.argv[1])
